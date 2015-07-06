<?php
namespace WebIM;
use Swoole;

class Server_im extends Swoole\Protocol\CometServer
{
    /**
     * @var Store\File;
     */
    protected $store;
    protected $info;// 记录信息 client_id => info数组
    protected $users;// 咨询用户 open_id => client_id 关系
    protected $servers;//服务人员 cid => client_id 关系

    const MESSAGE_MAX_LEN     = 4096; //单条消息不得超过1K
    const WORKER_HISTORY_ID   = 0;

    function __construct($config = array())
    {
        //将配置写入config.js
        $config_js = <<<HTML
var webim = {
    'server' : '{$config['server']['url']}'
}
HTML;
        file_put_contents(WEBPATH . '/client/config.js', $config_js);

        //检测日志目录是否存在
        $log_dir = dirname($config['webim']['log_file']);
        if (!is_dir($log_dir))
        {
            mkdir($log_dir, 0777, true);
        }
        if (!empty($config['webim']['log_file']))
        {
            $logger = new Swoole\Log\FileLog($config['webim']['log_file']);
        }
        else
        {
            $logger = new Swoole\Log\EchoLog;
        }
        $this->setLogger($logger);   //Logger

        /**
         * 使用文件或redis存储聊天信息
         */
        // $this->setStore(new \WebIM\Store\File($config['webim']['data_dir']));
        $this->setStore(new \WebIM\Store\Redis());
        $this->origin = $config['server']['origin'];
        parent::__construct($config);
    }

    function setStore($store)
    {
        $this->store = $store;
    }

    //自定义一些用户关系信息记录
    // 记录 client_id 对应的客服信息,建立 $this->servers表
    function setServer($client_id , $info)
    {
        $this->info[$client_id] = $info;
        $this->servers[$info['cid']] = $client_id;//cid代表客服系统id
        return  true;
    }

    // 记录client_id 对应微信客户端信息
    function setUser($client_id , $info)
    {
        $this->info[$client_id] = $info;
        $this->users[$info['open_id']] = $client_id;
        return true;
    }

    // 用户下线,清除数据
    function delClient($client_id)
    {
        $info = $this->info[$client_id];
        if (isset($info)) {
            if (isset($info['open_id'])) {
                unset($this->info[$client_id]);
                unset($this->users[$info['open_id']]);
            }elseif (isset($info['cid'])) {
                unset($this->ifno[$client_id]);
                unset($this->servers[$info['cid']]);
            }
            return true;
        }else{
            return false;
        }
    }

    /**
     * 下线时，清理部分数据,保留对话记录
     */
    function onExit($client_id)
    {
        $info = $this->info[$client_id];
        if (isset($info['open_id'])) {
            // 通知客服
            $to_server = $info['server_id'];
            // 根据 server_id 反向查找客服的 client_id
            $to_id = $this->servers[$to_server];
            $resMsg = array(
                'cmd' => 'offline',
                'fd' => $client_id,
                'from' => 0,
                // 'channal' => 0,
                'data' =>'下线了');
            $this->sendJson($to_id, $resMsg);
        }
        
        $this->delClient($client_id);
    }

    function onTask($serv, $task_id, $from_id, $data)
    {
        $req = unserialize($data);
        if ($req)
        {
            switch($req['cmd'])
            {
                case 'getHistory':
                    $history = array('cmd'=> 'getHistory', 'history' => $this->store->getHistory());
                    if ($this->isCometClient($req['fd']))
                    {
                        return $req['fd'].json_encode($history);
                    }
                    //WebSocket客户端可以task中直接发送
                    else
                    {
                        $this->sendJson(intval($req['fd']), $history);
                    }
                    break;
                case 'addHistory':
                    if (empty($req['msg']))
                    {
                        $req['msg'] = '';
                    }
                    $this->store->addHistory($req['fd'], $req['msg']);
                    break;
                default:
                    break;
            }
        }
    }

    function onFinish($serv, $task_id, $data)
    {
        $this->send(substr($data, 0, 32), substr($data, 32));
    }

    /**
     * 获取在线列表
     */
    function cmd_getOnline($client_id, $msg)
    {
        $resMsg = array(
            'cmd' => 'getOnline',
        );
        // $users = $this->store->getOnlineUsers();
        // $info = $this->store->getUsers(array_slice($users, 0, 100));
        // 只有客服端需要获取自己相关客户
        $info = $this->info[$client_id];
        $users = array();
        foreach ($this->info as $cli => $value) {
            if ( isset($value['server_id']) && ($value['server_id'] == $info['cid']) ) {
                $users[] = $value;
            }
        }

        $resMsg['users'] = $users;
        // $resMsg['list'] = $info;
        $this->sendJson($client_id, $resMsg);
    }

    /**
     * 获取历史聊天记录
     */
    function cmd_getHistory($client_id, $msg)
    {
        $task['fd'] = $client_id;
        $task['cmd'] = 'getHistory';
        $task['offset'] = '0,100';
        //在task worker中会直接发送给客户端
        $this->getSwooleServer()->task(serialize($task), self::WORKER_HISTORY_ID);
    }

    /**
     * 咨询微信登录
     * @param $client_id
     * @param $msg
     */
    function cmd_login($client_id, $msg)
    {
        // 可能需要做个验证
        $info['name'] = $msg['name'];
        $info['avatar'] = $msg['avatar'];
        $info['fd'] = $client_id;
        $info['open_id'] = $msg['open_id'];// 有openid人为是客户 - 根据openid 识别客户唯一性
        $info['server_id'] = 1;//由服务器分配
        // $info['server_id'] = $msg['server_id'];//由服务器分配


        //把会话存起来,记录用户信息
        $this->setUser( $client_id , $info );

        // 登陆成功
        $resMsg = array(
            'cmd' => 'login',
            'fd' => $client_id,
        );

        $this->sendJson($client_id, $resMsg);//回复服务器正确接受用户上线
        

        $resMsg = array(
            'cmd' => 'newUser',
            'fd' => $client_id,
            'name' => $info['name'],
            );

        $server_cli_id = $this->servers[$info['server_id']];
        $this->sendJson($server_cli_id, $resMsg);//通知客服,用户上线


    }

    /**
     * 后台客服人员登录
     * @param $client_id
     * @param $msg
     */
    function cmd_login_s($client_id, $msg)
    {
        // 可能需要做个验证
        $info['name'] = $msg['name'];
        $info['avatar'] = $msg['avatar'];
        $info['cid'] = $msg['cid'];
        $info['fd'] = $client_id;

        $this->setServer($client_id , $info);

        // 登陆成功
        $resMsg = array(
            'cmd' => 'login_s',
            'fd' => $client_id,
        );

        $this->sendJson($client_id, $resMsg);//回复服务器正确接受用户上线

        //可以给用户一个欢迎消息
    }

    /**
     * 发送信息请求 - 来自微信客户
     */
    function cmd_message($client_id, $msg)
    {
        // $msg 也记录下客服id
        $resMsg = $msg;
        $resMsg['from'] = $client_id;
        $resMsg['cmd'] = 'fromMsg';

        if (strlen($msg['data']) > self::MESSAGE_MAX_LEN)
        {
            $this->sendErrorMessage($client_id, 102, 'message max length is '.self::MESSAGE_MAX_LEN);
            return;
        }

        // 来的消息
        $from_user = $this->info[$client_id];
        // 客户给客服
        $to_server = $from_user['server_id'];

        // 根据 server_id 反向查找客服的 client_id
// 需要考虑客服掉线的情况.
        if (!isset($this->servers[$to_server])) {
            $to_id = current($this->servers);//给第一个客服Id
        }else{
            $to_id = $this->servers[$to_server];

        }
        
        $msg['type'] = 'from_user';

        // redis 增加聊天记录 - 可以改成数据库的
        $this->store->saveChatLog($from_user['open_id'] , $msg);

 
        $this->sendJson($to_id, $resMsg);

    }

    /**
     * 发送信息请求 - 来自客服
     */
    function cmd_message_s($client_id, $msg)
    {
        $resMsg = $msg;
        $resMsg['from'] = $client_id;
        $resMsg['cmd'] = 'fromMsg';

        if (strlen($msg['data']) > self::MESSAGE_MAX_LEN)
        {
            $this->sendErrorMessage($client_id, 102, 'message max length is '.self::MESSAGE_MAX_LEN);
            return;
        }

        // 来的消息
        $from_user = $this->info[$client_id];
        // 客服回复消息
        $to_id = $msg['to'];//client_id
        $msg['type'] = 'to_user';
        $to_user = $this->info[$to_id];
        
        // redis 增加聊天记录
        $this->store->saveChatLog($to_user['open_id'] , $msg);

 
        $this->sendJson($to_id, $resMsg);

    }

    /**
     * 接收到消息时
     * @see WSProtocol::onMessage()
     */
    function onMessage($client_id, $ws)
    {
        $this->log("onMessage #$client_id: " . $ws['message']);
        $msg = json_decode($ws['message'], true);
        if (empty($msg['cmd']))
        {
            $this->sendErrorMessage($client_id, 101, "invalid command");
            return;
        }
        $func = 'cmd_'.$msg['cmd'];
        if (method_exists($this, $func))
        {
            $this->$func($client_id, $msg);
        }
        else
        {
            $this->sendErrorMessage($client_id, 102, "command $func no support.");
            return;
        }
    }

    /**
     * 发送错误信息
    * @param $client_id
    * @param $code
    * @param $msg
     */
    function sendErrorMessage($client_id, $code, $msg)
    {
        $this->sendJson($client_id, array('cmd' => 'error', 'code' => $code, 'msg' => $msg));
    }

    /**
     * 发送JSON数据
     * @param $client_id
     * @param $array
     */
    function sendJson($client_id, $array)
    {
        $msg = json_encode($array);
        $this->send($client_id, $msg);
    }

    /**
     * 广播JSON数据
     * @param $client_id
     * @param $array
     */
    // function broadcastJson($sesion_id, $array)
    // {
    //     $msg = json_encode($array);
    //     $this->broadcast($sesion_id, $msg);
    // }

    // function broadcast($current_session_id, $msg)
    // {
    //     foreach ($this->users as $client_id => $name)
    //     {
    //         if ($current_session_id != $client_id)
    //         {
    //             $this->send($client_id, $msg);
    //         }
    //     }
    // }
}

