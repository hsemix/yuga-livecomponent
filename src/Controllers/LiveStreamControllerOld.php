<?php

namespace Yuga\Live;

use Exception;
use Yuga\Http\Request;

class LiveStreamControllerOld
{
    public function streamOld(Request $request)
    {
        @set_time_limit(0);
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');

        while (ob_get_level() > 0) {
            @ob_end_flush();
        }

        ob_implicit_flush(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $id = $request->get('id');
        $started = time();

        echo ": connected\n\n";
        echo str_repeat(' ', 4096) . "\n\n";

        flush();

        while (!connection_aborted()) {
            echo "event: ylc-refresh\n";
            echo "data: " . json_encode([
                'id' => $id,
                'time' => time(),
            ]) . "\n\n";

            flush();

            sleep(5);

            if (time() - $started >= 25) {
                echo "event: ylc-close\n";
                echo "data: {}\n\n";
                flush();
                break;
            }
        }

        exit;
    }

    public function stream(Request $request)
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Credentials: true');

        // Disable output buffering
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // Configuration
        $max_execution_time = 300; // 1 hour 3600
        $update_interval = 2; // 2 seconds
        $start_time = time();

        // Send initial connection event
        $this->sendSSEEvent([
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'Dashboard connected',
            'status' => 'connected'
        ], 'connection');

        try {
            $error_count = 0;
            $max_errors = 5;
            
            while (true) {
                // Check conditions to break the loop
                if (connection_aborted() || connection_status() != CONNECTION_NORMAL) {
                    break;
                }
                
                if (time() - $start_time > $max_execution_time) {
                    $this->sendSSEEvent([
                        'timestamp' => date('Y-m-d H:i:s'),
                        'message' => 'Dashboard session ended',
                        'status' => 'timeout'
                    ], 'session_end');
                    break;
                }
                
                try {
                    // Get streaming data
                    
                    $streamingData = [
                        
                    ];
                    
                    // Send dashboard update
                    $this->sendSSEEvent($streamingData, 'dashboard_update', time());
                    $error_count = 0; // Reset error count on success
                    
                } catch (Exception $e) {
                    $error_count++;
                    
                    $this->sendSSEEvent([
                        'timestamp' => date('Y-m-d H:i:s'),
                        'message' => 'Database error: ' . $e->getMessage(),
                        'error_count' => $error_count,
                        'status' => 'error'
                    ], 'error');
                    
                    // Break after too many consecutive errors
                    if ($error_count >= $max_errors) {
                        $this->sendSSEEvent([
                            'timestamp' => date('Y-m-d H:i:s'),
                            'message' => 'Too many errors, disconnecting',
                            'status' => 'fatal_error'
                        ], 'fatal_error');
                        break;
                    }
                }
                
                // Wait before next update
                sleep($update_interval);
            }
            
        } catch (Exception $e) {
            $this->sendSSEEvent([
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => 'Server error: ' . $e->getMessage(),
                'status' => 'critical_error'
            ], 'critical_error');
        }
    }

    protected function sendSSEEvent($data, $eventType = 'update', $id = null) 
    {
        if ($eventType) {
            echo "event: {$eventType}\n";
        }
        
        if ($id) {
            echo "id: {$id}\n";
        }
        
        $json_data = json_encode($data);
        if ($json_data !== false) {
            echo "data: {$json_data}\n\n";
        } else {
            $error_data = [
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => 'Data encoding error',
                'status' => 'error'
            ];
            echo "data: " . json_encode($error_data) . "\n\n";
        }
        $this->safeFlush();
    }

    protected function safeFlush() 
    {
        @ob_flush();
        @flush();
        if (ob_get_length() > 0) {
            ob_clean();
        }
    }
}
