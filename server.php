<?php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

class CrudServer implements MessageComponentInterface {
    private $clients;
    private $db;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->db = new mysqli("localhost", "root", "", "websocket_crud");

        if ($this->db->connect_error) {
            die("Database connection failed: " . $this->db->connect_error);
        }
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $messageData = json_decode($msg, true);
        $response = [];

        switch ($messageData['action']) {
            case 'create':
                $stmt = $this->db->prepare("INSERT INTO items (name) VALUES (?)");
                $stmt->bind_param("s", $messageData['data']['name']);
                $stmt->execute();
                $id = $stmt->insert_id;
                $stmt->close();
                $response = ['action' => 'create', 'id' => $id, 'data' => $messageData['data']];
                break;
            case 'read':
                $result = $this->db->query("SELECT * FROM items");
                $items = $result->fetch_all(MYSQLI_ASSOC);
                $result->free();
                $response = ['action' => 'read', 'data' => $items];
                break;
            case 'update':
                $stmt = $this->db->prepare("UPDATE items SET name = ? WHERE id = ?");
                $stmt->bind_param("si", $messageData['data']['name'], $messageData['id']);
                $stmt->execute();
                $stmt->close();
                $response = ['action' => 'update', 'id' => $messageData['id'], 'data' => $messageData['data']];
                break;
            case 'delete':
                $stmt = $this->db->prepare("DELETE FROM items WHERE id = ?");
                $stmt->bind_param("i", $messageData['id']);
                $stmt->execute();
                $stmt->close();
                $response = ['action' => 'delete', 'id' => $messageData['id']];
                break;
        }

        foreach ($this->clients as $client) {
            $client->send(json_encode($response));
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new CrudServer()
        )
    ),
    8081
);

$server->run();
