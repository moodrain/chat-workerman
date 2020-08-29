<?php

use Workerman\MySQL\Connection;
use Workerman\Timer;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

$worker = new Worker(LISTEN_URL);
$worker->name = APP_NAME;
$actionHandler = new ActionHandler($worker);
$worker->users = [];
$msgId = 1;

$worker->onWorkerStart = function($worker) {

    global $actionHandler;
    $actionHandler->redis();
    $actionHandler->db();

    // 每 10 秒扫描一次没有心跳的连接，直接关闭（踢下线
    Timer::add(10, function() use ($worker) {
        $now = time();
        foreach ($worker->connections as $connection) {
            if (empty($connection->lastMessageTime)) {
                $connection->lastMessageTime = $now;
                continue;
            }
            if ($now - $connection->lastMessageTime > HEARTBEAT_INTERVAL) {
                $connection->close();
            }
        }
    });

    // 每 30 秒扫描一次在线用户的 未收到确认的信息 再发送一遍，超过三次转存数据库
    Timer::add(30, function() use ($worker, $actionHandler) {
        $now = time();
        foreach($worker->connections as $connection) {
            if (! empty($connection->uid)) {
                $messages = $actionHandler->getUnreadMsg($connection->uid);
                foreach ($messages as $message) {
                    if (! isset($message['failSendCount']) || $message['failSendCount'] < 3) {
                        if (time() - $message['lastSendTime'] <= 30) {
                            continue;
                        }
                        ! isset($message['failSendCount']) && $message['failSendCount'] = 0;
                        $message['failSendCount']++;
                        $message['lastSendTime'] = $now;
                        $connection->send(json_encode($message));
                    } else {
                        $actionHandler->msg2Sql($message['id'], $message['data'], $connection->uid, $message['user']);
                        $actionHandler->markMsgRead($message['user'], $message['id']);
                    }
                }
            } else {
                $connection->close();
            }
        }
    });

};

$worker->onMessage = function($connection, $msg) {

    global $actionHandler;
    $connection->lastMessageTime = time();

    echo $msg . PHP_EOL;
    $msg = @json_decode($msg, true);
    if (! $msg) {
        return;
    }
    if (empty($msg['act'])) {
        return;
    }

    $token = $msg['token'] ?? null;
    $act = $msg['act'];
    $data = $msg['data'] ?? null;
    $to = $msg['to'] ?? null;
    $id = $msg['id'] ?? null;

    $actionHandler->handle($token, $act, $id, $data, $to, $connection);

};

$worker->onClose = function ($connection) use ($worker) {
    global $actionHandler;
    if (! empty($connection->uid)) {
        unset($worker->users[$connection->uid]);
        $actionHandler->redis()->sRem(REDIS_KEYS['onlineId'], $connection->uid);
    }
};

class ActionHandler
{
    private $worker;
    private $redis;
    private $db;

    public function __construct(Worker $worker) {
        $this->worker = $worker;
    }

    public function handle($token, $act, $id, $data, $to, $connection) {
        switch ($act) {
            case 'login': {
                $uid = $this->db()->select('id')->from('user')->where('token = :token')->bindValues(['token' => $token])->single();
                if (! $uid) {
                    $connection->close();
                    return;
                }
                $connection->uid = $uid;
                $this->worker->users[$uid] = $connection;
                $this->redis()->sAdd(REDIS_KEYS['onlineId'], $uid);
                $unreadMessages = $this->getUnreadMsg($uid);
                foreach ($unreadMessages as $unreadMessage) {
                    $connection->send(json_encode($unreadMessage));
                }
                break;
            }
            case 'chat:send': {
                if (! $this->redis()->sIsMember(REDIS_KEYS['onlineId'], $to)) {
                    $this->msg2Sql($id, $data, $to, $connection->uid);
                    return;
                }
                $message = $this->addUnreadMsg($connection->uid, $to, $data);
                $message['lastSendTime'] = time();
                $toConnection = $this->worker->users[$to] ?? null;
                $toConnection && $toConnection->send(json_encode($message));
                break;
            }
            case 'chat:read': {
                $this->markMsgRead($connection->uid, $id);
                break;
            }
        }
    }

    public function redis() {
        if (! $this->redis) {
            $this->redis = new Redis();
            $this->redis->connect(REDIS_CONFIG['host'], REDIS_CONFIG['port']);
        }
        return $this->redis;
    }

    public function db() {
        if (! $this->db) {
            $this->db = new Connection(MYSQL_CONFIG['host'], MYSQL_CONFIG['port'], MYSQL_CONFIG['user'], MYSQL_CONFIG['pass'], MYSQL_CONFIG['db']);
        }
        return $this->db;
    }

    public function getUnreadMsg($user) {
        $messages = $this->redis()->hGetAll(REDIS_KEYS['userUnreadMsg'] . $user);
        $messages = array_map(function($e) { return json_decode($e, true); }, $messages);
        return $messages;
    }

    public function addUnreadMsg($from, $to, $data) {
        $msgId = $this->genMsgId();
        $msg = [
            'id' => $msgId,
            'user' => $from,
            'data' => $data,
        ];
        $this->redis()->hSet(REDIS_KEYS['userUnreadMsg'] . $to, $msgId, json_encode($msg));
        return $msg;
    }

    public function markMsgRead($user, $id) {
        $this->redis()->hDel(REDIS_KEYS['userUnreadMsg'] . $user, $id);
    }

    public function msg2Sql($id, $data, $toId, $fromId) {
        $id = $id ?? $this->genMsgId();
        $this->db()->insert('chat_message_unread')->cols([
            'message_id' =>  $id,
            'from_user_id' => $fromId,
            'to_user_id' => $toId,
            'created_at' => date('Y-m-d H:i:s'),
            'data' => json_encode($data),
        ])->query();
    }

    public function genMsgId() {
        global $msgId;
        return (++$msgId) % 100000000;
    }

}

Worker::runAll();