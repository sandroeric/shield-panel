package main

import (
	"context"
	"log"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"

	"shieldpanel/worker/internal/db"
	"shieldpanel/worker/internal/queue"
)

func main() {
	log.Println("Starting ShieldPanel Security Worker Service...")

	// 1. Load Configurations from Env
	dbHost := getEnv("DB_HOST", "postgres")
	dbPortStr := getEnv("DB_PORT", "5432")
	dbUser := getEnv("DB_USER", "postgres")
	dbPass := getEnv("DB_PASS", "postgres")
	dbName := getEnv("DB_NAME", "shieldpanel")

	rmqHost := getEnv("RABBITMQ_HOST", "rabbitmq")
	rmqPortStr := getEnv("RABBITMQ_PORT", "5672")
	rmqUser := getEnv("RABBITMQ_USER", "guest")
	rmqPass := getEnv("RABBITMQ_PASS", "guest")

	dbPort, err := strconv.Atoi(dbPortStr)
	if err != nil {
		log.Fatalf("Invalid DB_PORT value: %v", err)
	}
	rmqPort, err := strconv.Atoi(rmqPortStr)
	if err != nil {
		log.Fatalf("Invalid RABBITMQ_PORT value: %v", err)
	}

	// 2. Setup Context and Graceful Shutdown Signal Capturer
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)

	// 3. Connect to PostgreSQL
	database, err := db.Connect(dbHost, dbPort, dbUser, dbPass, dbName)
	if err != nil {
		log.Fatalf("Failed to initialize database: %v", err)
	}
	defer database.Close()

	// 4. Connect to RabbitMQ
	rmqConn, err := queue.Connect(rmqHost, rmqPort, rmqUser, rmqPass)
	if err != nil {
		log.Fatalf("Failed to connect to RabbitMQ broker: %v", err)
	}
	defer rmqConn.Close()

	// 5. Instantiate and Start Consumer
	consumer, err := queue.NewConsumer(rmqConn, database)
	if err != nil {
		log.Fatalf("Failed to create queue consumer: %v", err)
	}
	defer consumer.Close()

	if err := consumer.Start(ctx); err != nil {
		log.Fatalf("Failed to start queue consumer: %v", err)
	}

	log.Println("ShieldPanel worker service is running. Awaiting messages...")

	// 6. Block until termination signal
	sig := <-sigChan
	log.Printf("Received termination signal (%v). Initiating graceful shutdown...", sig)

	// Cancel context to stop processing new queue deliveries
	cancel()

	// Drain in-flight jobs
	log.Println("Waiting for in-flight jobs to complete...")
	drainChan := make(chan struct{})
	go func() {
		consumer.Wait()
		close(drainChan)
	}()

	select {
	case <-drainChan:
		log.Println("All in-flight jobs drained successfully.")
	case <-time.After(15 * time.Second):
		log.Println("Shutdown timeout exceeded. Some jobs may have been interrupted.")
	}

	log.Println("ShieldPanel worker service stopped.")
}

func getEnv(key, defaultVal string) string {
	if val, ok := os.LookupEnv(key); ok {
		return val
	}
	return defaultVal
}
