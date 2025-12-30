<?php
declare(strict_types=1);

namespace ChernegaSergiy\PrometheusExporter;

final class PrometheusHttpServer{
    /** @var resource|null */
    private $socket = null;
    /** @var callable():string */
    private $renderer;
    private bool $isClosed = false;

    /**
     * @param callable():string $renderer
     * @param object|null $logger
     */
    public function __construct(string $address, int $port, int $backlog, $logger, callable $renderer){
        $this->renderer = $renderer;

        $context = stream_context_create([
            "socket" => [
                "so_reuseaddr" => 1,
                "backlog" => $backlog,
            ],
        ]);

        $endpoint = "tcp://{$address}:{$port}";
        $socket = @stream_socket_server(
            $endpoint,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if($socket === false){
            if(is_object($logger) && method_exists($logger, "error")){
                $logger->error("Failed to start Prometheus exporter HTTP server on {$endpoint}: ({$errno}) {$errstr}");
            }else{
                error_log("PrometheusExporter: failed to bind {$endpoint}: ({$errno}) {$errstr}");
            }
            return;
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;
    }

    public function isReady() : bool{
        return $this->socket !== null && !$this->isClosed;
    }

    public function tick() : void{
        if(!$this->isReady()){
            return;
        }

        while(($client = @stream_socket_accept($this->socket, 0)) !== false){
            /** @var resource $client */
            stream_set_blocking($client, true);
            $request = "";
            while(($line = fgets($client)) !== false){
                $request .= $line;
                if($line === "\r\n" || $line === "\n"){
                    break;
                }
            }

            if($request === ""){
                fclose($client);
                continue;
            }

            $firstLine = strtok($request, "\r\n") ?: "";
            $parts = explode(" ", $firstLine, 3);
            $method = $parts[0] ?? "";
            $uri = $parts[1] ?? "/";

            if($method !== "GET" && $method !== "HEAD"){
                $this->writeResponse($client, "405 Method Not Allowed", "Method Not Allowed", [
                    "Allow" => "GET",
                ]);
                continue;
            }

            if($uri !== "/metrics"){
                $this->writeResponse($client, "404 Not Found", "Not Found");
                continue;
            }

            $body = $method === "HEAD" ? "" : ($this->renderer)();
            $this->writeResponse($client, "200 OK", $body, [
                "Content-Type" => "text/plain; version=0.0.4",
                "Cache-Control" => "no-cache",
            ]);
        }
    }

    public function shutdown() : void{
        if($this->socket !== null && !$this->isClosed){
            fclose($this->socket);
            $this->isClosed = true;
        }
    }

    /**
     * @param resource $client
     * @param array<string, string> $headers
     */
    private function writeResponse($client, string $status, string $body, array $headers = []) : void{
        $payload = "HTTP/1.1 {$status}\r\n";
        $headers["Content-Length"] = (string) strlen($body);
        $headers["Connection"] = "close";

        foreach($headers as $key => $value){
            $payload .= "{$key}: {$value}\r\n";
        }

        $payload .= "\r\n{$body}";

        fwrite($client, $payload);
        fclose($client);
    }
}
