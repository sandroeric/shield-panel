<?php
namespace ShieldPanel\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class QueueService {
    private static $connection = null;
    private static $channel = null;

    private static function init() {
        if (self::$connection !== null) {
            return;
        }

        $host = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
        $port = getenv('RABBITMQ_PORT') ?: 5672;
        $user = getenv('RABBITMQ_USER') ?: 'shieldpanel_mq';
        $pass = getenv('RABBITMQ_PASS') ?: 'shieldpanel_mq_pass';

        $maxRetries = 5;
        $delay = 1;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Connect to RabbitMQ
                self::$connection = new AMQPStreamConnection($host, $port, $user, $pass);
                self::$channel = self::$connection->channel();
                
                // Declare topic exchange (durable)
                self::$channel->exchange_declare(
                    'shieldpanel.events',
                    'topic',
                    false, // passive
                    true,  // durable
                    false  // auto_delete
                );

                // Declare main security jobs queue (durable)
                self::$channel->queue_declare(
                    'security.jobs',
                    false, // passive
                    true,  // durable
                    false, // exclusive
                    false  // auto_delete
                );

                // Bind keys we expect to queue in security.jobs
                $routingKeys = [
                    'scan.requested',
                    'protection.enabled',
                    'protection.disabled',
                    'domain.created',
                    'domain.deleted',
                    'account.suspended'
                ];

                foreach ($routingKeys as $key) {
                    self::$channel->queue_bind('security.jobs', 'shieldpanel.events', $key);
                }
                
                return;
            } catch (\Exception $e) {
                if ($attempt === $maxRetries) {
                    throw new \Exception("RabbitMQ connection failed after $maxRetries attempts: " . $e->getMessage());
                }
                error_log("RabbitMQ connection attempt $attempt failed. Retrying in $delay seconds...");
                sleep($delay);
                $delay *= 2;
            }
        }
    }

    /**
     * Publishes a message to the shieldpanel.events exchange with the specified routing key.
     */
    public static function publish($routingKey, array $payload) {
        self::init();

        $messageBody = json_encode($payload);
        $msg = new AMQPMessage($messageBody, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json'
        ]);

        self::$channel->basic_publish($msg, 'shieldpanel.events', $routingKey);
        error_log("Published event '$routingKey' with payload: " . $messageBody);
    }

    public static function close() {
        try {
            if (self::$channel) {
                self::$channel->close();
            }
            if (self::$connection) {
                self::$connection->close();
            }
        } catch (\Exception $e) {
            // Ignore on shutdown
        }
    }
}
// Register shutdown handler to close connection
register_shutdown_function([QueueService::class, 'close']);
