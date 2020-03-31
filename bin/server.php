<?php
/**
 * Created by PhpStorm.
 * User: QIN
 * Date: 2020/3/11
 * Time: 22:03
 */

Swoole\Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL | SWOOLE_HOOK_CURL]);
require_once __DIR__ . '/../vendor/autoload.php';

class Server {

    const MASTER_NAME = 'swoole-master';

    const MANAGER_NAME = 'swoole-manager';

    const WORKER_NAME = 'swoole-worker';

    const TASK_WORKER_NAME = 'swoole-taskworker';

    const HOST = '127.0.0.1';

    const PORT = 9501;

    const LOG_PATH = __DIR__ . '/../runtime/log';

    const CONFIG_PATH = __DIR__ . '/../config';

    /**
     * @var Swoole\Server
     */
    private $server;

    /**
     * @var Swoole\Table
     */
    private $table;

    /**
     * @var array
     */
    private $tasks;


    private function serverConfig() {
        return [
            'worker_num' => 1,//默认cpu数量
            'task_worker_num' => 4,
            'task_enable_coroutine' => true,
            'task_ipc_mode' => 1,
            'dispatch_mode' => 2,
            'daemonize' => 1,
            'log_file' => static::LOG_PATH . '/server.log',
            'pid_file' => static::LOG_PATH . '/server.pid',
            'reload_async' => true,
            'max_wait_time' => 10,
            'max_coroutine' => 10000,
            'open_length_check' => true,
            'open_eof_check' => true,
            'package_eof' => "\r\n",
        ];
    }


    public function setServer() {

        if (is_file(static::LOG_PATH . '/server.pid')) {
            $pid = file_get_contents(static::LOG_PATH . '/server.pid');
            if (Swoole\Process::kill((int)$pid, 0)) {
                echo "\033[31mswoole server running, pid : {$pid} ,if you want to close server. kill -15 {$pid}\033[0m\n";
                exit(0);
            }
        }
        swoole_set_process_name(static::MASTER_NAME);
        $this->server = new Swoole\Server(static::HOST, static::PORT);
        $this->server->set(
            $this->serverConfig()
        );
        $this->table = new \Swoole\Table(1024);
        $this->table->column('num', Swoole\Table::TYPE_INT, 4);
        $this->table->create();
    }


    public function __construct() {
        $this->setServer();
        $this->server->on('managerstart', function ($server) {
            swoole_set_process_name(static::MANAGER_NAME);
        });
        $this->server->on('start', function ($server) {
            $time = date('Y-m-d H:i:s');
            echo "[{$time} #{$server->manager_pid}]  INFO    swoole server start. master : {$server->master_pid} , manager:{$server->manager_pid}" . PHP_EOL;
        });
        $this->server->on('workerstart', function ($server, $workerId) {
            if ($server->taskworker) {
                swoole_set_process_name(static::TASK_WORKER_NAME . $workerId);
            } else {
                swoole_set_process_name(static::WORKER_NAME . $workerId);
            }
            $this->loadConfig();
            if ($workerId === 0) {
                //event
                $time = time();
                $nextime = strtotime(date('Y-m-d H:i:00', $time)) + 60;
                $nextime = $nextime - $time - 1 > 0 ? $nextime - $time - 1 : 59;
                Swoole\Timer::after($nextime * 1000, function () {
                    $rule = helper\Config::get('rule');
                    $this->tasks = [];
                    Swoole\Timer::tick(1000, function () use ($rule) {
                        $time = time();
                        $s = date('s', $time);
                        if ((int)$s === 0) {
                            foreach ($rule as $key => $value) {
                                $result = helper\ParseCrontab::parse($value['rule'], $time);
                                if ($result) {
                                    $this->tasks[$key] = $result;
                                }
                            }
                        }
                        foreach ($this->tasks as $index => $task) {
                            if (isset($task[(int)$s])) {
                                $this->server->task(json_encode($rule[$index]));
                            }
                        }
                    });
                });
            }
        });
        $this->server->on('receive', function ($server, $fd, $reactorId, $data) {
            $tab = "\r\n";
            $data = explode($tab, trim($data));
            foreach ($data as $v) {
                $this->command($server, $fd, $v);
            }
        });
        $this->server->on('task', function ($server, $task) {
            $msg = json_decode($task->data, true);
            if ($msg['unique'] && $this->table->incr($msg['key'], 'num') > 1) {
                return true;
            }
            if ($msg['log']) {
                $this->log("任务id:{$task->id} {$msg['name']} >>> 任务开始");
            }
            $class = $msg['cmd'][0];
            $exc = $msg['cmd'][1];
            $obj = new $class;
            $result = false;
            for ($i = -1; $i < $msg['again']; $i++) {
                try {
                    $result = $obj->$exc();
                } catch (Throwable $throwable) {
                    $this->log(var_export(['key' => $msg['key'], 'errormessage' => $throwable->getMessage(), 'line' => $throwable->getLine(), 'id' => $task->id], true), 'error');
                }
                if ($result === true) {
                    break;
                }
            }
            if ($msg['log']) {
                $this->log("任务id:{$task->id} {$msg['name']} >>> 任务结束");
            }
            $this->table->decr($msg['key'], 'num');
        });
        $this->server->on('finish', function ($server, $task_id, $data) {

        });
        $this->server->on('managerstop', function ($server) {

        });
        $this->server->on('shutdown', function () {

        });
        $this->server->on('workererror', function ($server, $worker_id, $worker_pid, $exit_code, $signal) {
            $this->log(var_export(['worker_id' => $worker_id, 'worker_pid' => $worker_pid, 'exit_code' => $exit_code, 'signal' => $signal], true), 'error');
        });
        $this->server->on('workerexit', function ($server, $worker_id) {
            Swoole\Timer::clearAll();
        });
        $this->server->start();
    }


    public function loadConfig() {
        helper\Config::load(static::CONFIG_PATH);
        helper\Redis::load();
        helper\Db::load();
    }


    public function command($server, $fd, $cmd) {
        $tab = "\r\n";
        /**
         * @var Swoole\Server $server
         */
        $cmd = json_decode($cmd, true);
        if (isset($cmd['type'])) {
            switch ($cmd['type']) {
                case 'get' :
                    $text = '';
                    foreach ($this->table as $index => $item) {
                        $text .= $index . ":" . $item['num'] . PHP_EOL;
                    }
                    $server->send($fd, $text . $tab);
                    break;
                case 'reload' :
                    if (isset($cmd['ext']) && $cmd['ext'] == 'task') {
                        $server->reload(true);
                        $server->send($fd, "swoole server is reloading task workers now{$tab}");
                    } else {
                        $server->reload();
                        $server->send($fd, "swoole server is reloading all workers now{$tab}");
                    }
                    break;
                case 'shutdown' :
                    $server->send($fd, "swoole server shutdown{$tab}");
                    $server->shutdown();
                    break;
                case 'stats' :
                    $server->send($fd, var_export($server->stats(), true) . $tab);
                    break;
                case 'log' :
                    Swoole\Process::kill($server->master_pid, SIGRTMIN);
                    $server->send($fd, 'reload log success' . PHP_EOL);
                    break;
                case 'reset' :
                    if (isset($cmd['ext']) && $cmd['ext']) {
                        $boole = false;
                        if ($this->table->exist((string)$cmd['ext'])) {
                            $boole = $this->table->set((string)$cmd['ext'], ['num' => 0]);
                        }
                        $boole ? $server->send($fd, "reset {$cmd['ext']} success" . PHP_EOL) : $server->send($fd, "reset {$cmd['ext']} fail" . PHP_EOL);
                    }
                    break;
            }
        }
    }


    public function log($data = '', $type = 'task', $enable_coroutine = 0) {
        $time = microtime(true);
        $month = date('Ym', (int)$time);
        $date = date('Ymd', (int)$time);
        $sfm = date('Y-m-d H:i:s', (int)$time);
        $dir = static::LOG_PATH . DIRECTORY_SEPARATOR . $month;
        $file = "{$date}_{$type}.log";
        $text = <<<EOF
[{$sfm}] {$data} Runtime:{$time}
EOF;
        if ($enable_coroutine === 0) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755);
            }
            file_put_contents($dir . DIRECTORY_SEPARATOR . $file, trim($text) . PHP_EOL, FILE_APPEND);
        } else {
            Swoole\Coroutine::create(function () use ($dir, $file, $text) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755);
                }
                file_put_contents($dir . DIRECTORY_SEPARATOR . $file, trim($text) . PHP_EOL, FILE_APPEND);
            });
        }
    }
}

(new Server());