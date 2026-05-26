package queue

import (
	"context"
	"fmt"
	"log"
	"sync"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
	"shieldpanel/worker/internal/db"
	"shieldpanel/worker/internal/handler"
)

type Consumer struct {
	conn *amqp.Connection
	ch   *amqp.Channel
	db   *db.Database
	wg   sync.WaitGroup
}

// Connect establishes connection to RabbitMQ with an exponential backoff retry loop.
func Connect(host string, port int, user, password string) (*amqp.Connection, error) {
	connStr := fmt.Sprintf("amqp://%s:%s@%s:%d/", user, password, host, port)
	
	var conn *amqp.Connection
	var err error
	maxRetries := 10
	delay := 1 * time.Second

	for i := 1; i <= maxRetries; i++ {
		conn, err = amqp.Dial(connStr)
		if err == nil {
			log.Printf("Successfully connected to RabbitMQ after %d attempts", i)
			return conn, nil
		}

		log.Printf("RabbitMQ connection attempt %d failed: %v. Retrying in %v...", i, err, delay)
		time.Sleep(delay)
		delay *= 2
	}

	return nil, fmt.Errorf("could not connect to RabbitMQ after %d attempts: %w", maxRetries, err)
}

// NewConsumer instantiates a new Consumer instance.
func NewConsumer(conn *amqp.Connection, database *db.Database) (*Consumer, error) {
	ch, err := conn.Channel()
	if err != nil {
		return nil, err
	}

	return &Consumer{
		conn: conn,
		ch:   ch,
		db:   database,
	}, nil
}

// Start sets up RabbitMQ exchanges/queues and begins consuming.
func (c *Consumer) Start(ctx context.Context) error {
	// Declare exchange
	err := c.ch.ExchangeDeclare(
		"shieldpanel.events",
		"topic",
		true,  // durable
		false, // auto-delete
		false, // internal
		false, // no-wait
		nil,   // arguments
	)
	if err != nil {
		return fmt.Errorf("failed to declare exchange: %w", err)
	}

	// Declare queue
	_, err = c.ch.QueueDeclare(
		"security.jobs",
		true,  // durable
		false, // auto-delete
		false, // exclusive
		false, // no-wait
		nil,   // arguments
	)
	if err != nil {
		return fmt.Errorf("failed to declare queue: %w", err)
	}

	// Bind keys
	routingKeys := []string{
		"scan.requested",
		"protection.enabled",
		"protection.disabled",
		"domain.created",
		"domain.deleted",
		"account.suspended",
	}
	for _, key := range routingKeys {
		err = c.ch.QueueBind(
			"security.jobs",
			key,
			"shieldpanel.events",
			false, // no-wait
			nil,   // arguments
		)
		if err != nil {
			return fmt.Errorf("failed to bind routing key %s: %w", key, err)
		}
	}

	// Consume channel
	deliveries, err := c.ch.Consume(
		"security.jobs",
		"",    // consumer-tag
		false, // auto-ack (explicit ACK is required per specs)
		false, // exclusive
		false, // no-local
		false, // no-wait
		nil,   // arguments
	)
	if err != nil {
		return fmt.Errorf("failed to start consuming deliveries: %w", err)
	}

	go func() {
		for {
			select {
			case <-ctx.Done():
				log.Println("Graceful shutdown initiated: stopping consumer deliveries loop...")
				return
			case msg, ok := <-deliveries:
				if !ok {
					log.Println("Deliveries queue channel closed.")
					return
				}

				c.wg.Add(1)
				go func(m amqp.Delivery) {
					defer c.wg.Done()

					if err := c.process(m); err != nil {
						log.Printf("Process message error: %v. Requeueing message...", err)
						_ = m.Nack(false, true)
					} else {
						_ = m.Ack(false)
					}
				}(msg)
			}
		}
	}()

	return nil
}

// process dispatches messages to the corresponding domain logic handler.
func (c *Consumer) process(m amqp.Delivery) error {
	if m.RoutingKey == "scan.requested" {
		return handler.HandleScanRequested(c.db, m.Body)
	}
	
	return handler.HandleLifecycleHook(c.db, m.RoutingKey, m.Body, c.publish)
}

// publish writes a new message back to RabbitMQ (used to queue initial scan from hook handler).
func (c *Consumer) publish(routingKey string, body []byte) error {
	return c.ch.PublishWithContext(
		context.Background(),
		"shieldpanel.events",
		routingKey,
		false,
		false,
		amqp.Publishing{
			ContentType:  "application/json",
			DeliveryMode: amqp.Persistent,
			Body:         body,
		},
	)
}

// Close cleans up channels and connections.
func (c *Consumer) Close() {
	if c.ch != nil {
		_ = c.ch.Close()
	}
}

// Wait blocks until all current in-flight workers finish execution.
func (c *Consumer) Wait() {
	c.wg.Wait()
}
