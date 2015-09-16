<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 聊天主逻辑
 * 主要是处理 onMessage onClose
 */
use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\Lib\Store;
use \GatewayWorker\Lib\Db;

class Event
{

    // 临时数据,在开始或结束时,应想法清理下
    static $agrent_key = "wiz_ROOM_AGRENT_LIST";
    static $client_key = "wiz_ROOM_CLIENT_LIST";

    /**
     * @param $client_id
     * @param $message
     * @return bool|void
     */
    public static function onMessage( $client_id, $message )
    {
        // debug
        echo "client_id:$client_id session:" . json_encode( $_SESSION ) . " onMessage:" . $message . "\n";

        // 客户端传递的是json数据
        $message_data = json_decode( $message, TRUE );
        if ( !$message_data ) {
            return;
        }

        // 根据类型执行不同的业务
        switch ( $message_data[ 'cmd' ] ) {
            // 客户端回应服务端的心跳
            case 'pong':
                return;
            // 客户端登录 message格式: {cmd:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'login':
                $open_id = $message_data[ 'open_id' ];
                // 把昵称,open_id放到session中
                $name = htmlspecialchars( $message_data[ 'name' ] );
                $_SESSION[ 'open_id' ] = $message_data[ 'open_id' ];
                $_SESSION[ 'name' ] = $message_data[ 'name' ];
                $_SESSION[ 'type' ] = 'client';
                // 记录在线用户
                self::setClient( $client_id, 'client', $message_data );

                // 转播给当前房间的所有客户端，xx进入聊天室 message {type:login, client_id:xx, name:xx} 
                $new_message = array( 'cmd' => $message_data[ 'cmd' ], 'fd' => $client_id, 'open_id' => $open_id, 'name' => $name, 'time' => date( 'Y-m-d H:i:s' ) );

                // 获取全部在线客服,进行广播
//                $all_agrents = self::getAllClients( 'server' );

                // 多客服,需要有个分配用户的逻辑
                self::distribution( 'userOn', $client_id, json_encode( $new_message ) );
//                    Gateway::sendToClient( $server_id, json_encode( $new_message ) );//通知客服用户上线
                $message = array( 'cmd' => 'message', 'data' => '客服自动回复内容!' );
                Gateway::sendToCurrentClient( json_encode( $message ) );

                //这里可以加些欢迎提示语
                return;

            // 客户端发言 message: {cmd:say, to_client_id:xx, content:xx}
            case 'message':
                $open_id = $_SESSION[ 'open_id' ];
                $message = array(
                    'cmd'          => 'message',
                    'fd'           => $client_id,
                    'type'         => 'client',
                    'open_id'      => $open_id,
                    'to_client_id' => 'all',
                    'data'         => nl2br( htmlspecialchars( $message_data[ 'data' ] ) ),
                    'time'         => date( 'Y-m-d H:i:s' ),
                );
                // 获取全部在线客服,进行广播,针对全部客服?
//                $all_agrents = self::getAllClients( 'server' );
//                return Gateway::sendToAll( json_encode( $message ), array_keys( $all_agrents ) );
                $server_id = self::distribution( 'cliMessage', $client_id );
                // 生成聊天记录到数据库
                self::history( 'set', $message, $server_id );

                return Gateway::sendToClient( $server_id, json_encode( $message ) );
            // 服务端登录 message格式: {type:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'login_s':
                // (未实现)通过session检测是否为真实客服,这个数据由页面那里放入redis中???

                $session_id = $message_data[ 'cid' ];
                // 把昵称,open_id放到session中
                $name = htmlspecialchars( $message_data[ 'name' ] );

                $_SESSION[ 'cid' ] = $message_data[ 'cid' ];
                // 通过关联,获取客服的系统id
                $_SESSION[ 'system_id' ] = 1;//先写默认值
                $_SESSION[ 'name' ] = $name;
                $_SESSION[ 'type' ] = 'server';

                // 转播给全部在线客服端，xx进入聊天室 message {type:login, client_id:xx, name:xx}
                $new_message = array( 'cmd' => $message_data[ 'cmd' ], 'client_id' => $client_id, 'cid' => $session_id, 'name' => $name, 'time' => date( 'Y-m-d H:i:s' ) );
                // 记录在线客服 , 数据暂时没想好
                self::setClient( $client_id, 'server', $_SESSION );

                $_SESSION[ 'onLine' ] = self::distribution( 'serverOn', $client_id );//起始状态分配的用户
                return;
            case 'getOnline':
                // 获取全部在线用户
                $all_clients = self::getAllClients( 'client' );
//                var_dump($all_clients);
                $online = $_SESSION[ 'onLine' ];
                if ( empty( $online ) ) {
                    $online = array();
                }
                $online = array_flip( $online );
//                var_dump($online);
                $all_clients = array_intersect_key( $all_clients, $online );
//                var_dump($all_clients);
                if ( empty( $all_clients ) ) {
                    $all_clients = array();
                }
//                var_dump($all_clients);
                //获取分配给server的用户
                $all_clients = array_values( $all_clients );
                // 转播给全部在线客服端，xx进入聊天室 message {type:login, users:xx, name:xx}
                $message = array( 'cmd' => $message_data[ 'cmd' ], 'users' => $all_clients, 'time' => date( 'Y-m-d H:i:s' ) );
                Gateway::sendToCurrentClient( json_encode( $message ) );

                return;
            case 'message_s':
                $message_data[ 'time' ] = date( 'Y-m-d H:i:s' );
                self::history( 'set', $message_data );

                return Gateway::sendToClient( $message_data[ 'to' ], json_encode( $message_data ) );
            case 'getHistory':
                $history = array( 'cmd' => 'history', 'fd' => $message_data[ 'fd' ], 'offset' => $message_data[ 'offset' ], 'open_id' => $message_data[ 'open_id' ] );
                $log = self::history( 'get', $history );
                $history[ 'data' ] = $log;

                return Gateway::sendToClient( $client_id, json_encode( $history ) );

        }
    }


    /**
     * 当客户端断开连接时
     * @param integer $client_id 客户端id
     */
    public static function onClose( $client_id )
    {
        // debug
//        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id onClose:''\n";

        if ( $_SESSION[ 'type' ] == 'client' ) {
            self::delClient( $client_id );
            // 获取全部在线客服,进行广播
            $all_agrents = self::getAllClients( 'server' );
            $message = array( 'cmd' => 'offline', 'fd' => $client_id );
            Gateway::sendToAll( json_encode( $message ), array_keys( $all_agrents ) );
            self::distribution( 'userOff', $client_id );
        } elseif ( $_SESSION[ 'type' ] == 'server' ) {
            self::delClient( $client_id, 'server' );
            self::distribution( 'serverOff', $client_id );
        }

    }


    // 用户上线记录
    public static function setClient( $client_id, $type = 'client', $data )
    {
        switch ( $type ) {
            case 'client':
                $key = self::$client_key;
                break;
            case 'server':
                $key = self::$agrent_key;
                break;
            default:
                return FALSE;
        }

        $store = Store::instance( 'dialog' );
        $data[ 'fd' ] = $client_id;//追加值
        return $store->HSET( $key, $client_id, $data );
    }

    // 取得用户上线记录
    public static function getClient( $client_id, $type = 'client' )
    {
        switch ( $type ) {
            case 'client':
                $key = self::$client_key;
                break;
            case 'server':
                $key = self::$agrent_key;
                break;
            default:
                return FALSE;
        }

        $store = Store::instance( 'dialog' );

        return $store->HGET( $key, $client_id );
    }

    // 用户下线
    public static function delClient( $client_id, $type = 'client' )
    {
        switch ( $type ) {
            case 'client':
                $key = self::$client_key;
                break;
            case 'server':
                $key = self::$agrent_key;
                break;
            default:
                return FALSE;
        }
        $store = Store::instance( 'dialog' );

        return $store->HDEL( $key, $client_id );
    }

    // 记录全部用户数据到redis
    public static function getAllClients( $type = 'client' )
    {
        switch ( $type ) {
            case 'client':
                $key = self::$client_key;
                break;
            case 'server':
                $key = self::$agrent_key;
                break;
            default:
                return FALSE;
        }

        $store = Store::instance( 'dialog' );
        // 获取全部在线客户端
        $online = Gateway::getOnlineStatus();
        $store_client = $store->HKEYS( $key );
        if ( empty( $store_client ) ) {
            $store_client = array();
        }
        $diff = array_diff( $store_client, $online );
        $store->MULTI();
        foreach ( $diff as $k => $v ) {
            $store->HDEL( $key, $v );
        }
        $store->EXEC();

        $clients = $store->HGETALL( $key );

        return $clients;
    }

    /**
     * 用户分配中心,处理四个事件:
     * @param $env 1:客服上线;2:客服下线;3:用户上线;4:用户下线;5:用户发消息
     * @param $client_id
     *
     * @return mix $client_id 返回分配的对应id或其他
     */
    public static function distribution( $env, $client_id )
    {
        $store = Store::instance( 'dialog' );
        $user_to_server = 'wiz_user_to_server-';//type:string
        $server_to_user = 'wiz_server_to_user-';//type:set
        switch ( $env ) {
            case 'userOn':
                //用户上线,分配用户客服的client_id,没有返回0
                //查询全部在线客服
                $servers = self::getAllClients( 'server' );
//                var_dump($servers);
                if ( count( $servers ) >= 2 ) {
                    // 找出服务用户最少的客服
                    foreach ( $servers as $k => $v ) {
                        $server_users[ $k ] = count( $store->SMEMBERS( $server_to_user . $k ) );//记录下哪个最少
                    }
                    $return = array_keys( $server_users, min( $server_users ) );
                    $return = current( $return );
                } elseif ( count( $servers ) == 1 ) {
                    // 只有一个就不用算了.
                    $return = key( $servers );
                } elseif ( count( $servers ) == 0 ) {
                    // 没人上班的情况
                    $return = 0;
                }
                // 分配数据
                $store->sadd( $server_to_user . $return, $client_id );
                $store->set( $user_to_server . $client_id, $return );

                // 取得用户信息
                $client_info = self::getClient( $client_id, 'client' );
                $new_message = array( 'cmd' => 'login', 'fd' => $client_info[ 'fd' ], 'open_id' => $client_info[ 'open_id' ], 'name' => $client_info[ 'name' ], 'time' => date( 'Y-m-d H:i:s' ) );
                Gateway::sendToClient( $return, json_encode( $new_message ) );
                $history = array( 'cmd' => 'history', 'fd' => $client_info[ 'fd' ], 'name' => $client_info[ 'name' ], 'offset' => 0, 'open_id' => $client_info[ 'open_id' ] );
                $log = self::history( 'get', $history );
                $history[ 'data' ] = $log;
                Gateway::sendToClient( $return, json_encode( $history ) );

                return $return;
                break;
            case 'userOff':
                //用户下线,回收相关资源
                $server_id = $store->get( $user_to_server . $client_id );
                $store->del( $user_to_server . $client_id );
                $store->srem( $server_to_user . $server_id, $client_id );

                return TRUE;
                break;
            case 'serverOn':
                // 客服上线,记录资源,这个在self::setClient中已处理
                $store->del( $server_to_user . $client_id );//清理以前的旧数据
                //分配全部给0的用户
                $server0 = $store->SMEMBERS( $server_to_user . '0' );
//                var_dump($server0);
                if ( count( $server0 ) != 0 ) {
                    foreach ( $server0 as $user ) {
                        // 重新分配用户到server
                        self::distribution( 'userOn', $user );
                    }
                    $store->del( $server_to_user . '0' );
                }

                return $server0;
                break;
            case 'serverOff':
                $users = $store->SMEMBERS( $server_to_user . $client_id );
                $store->del( $server_to_user . $client_id );//删除关系
                foreach ( $users as $user ) {
                    // 重新分配用户到server
                    self::distribution( 'userOn', $user );
                }

                return TRUE;
                break;
            case 'cliMessage':
                //给客户端分配收取消息的客服人员
                $server_id = $store->get( $user_to_server . $client_id );

                return $server_id;
                break;
            default:
                break;
        }

        return FALSE;
    }

    // 获取/设置聊天记录
    public static function history( $env, $data, $server_id = 0 )
    {
        $db = Db::instance( 'log' );
        if ( !isset( $data[ 'limit' ] ) ) {
            $data[ 'limit' ] = 10;
        }

        // open_id仅包含特殊字符-,没有其它特殊字符,因此可以直接过滤掉,防止注入
        $search = array( '"', '\'', '`', "\\" );
        $replace = array( '', '', '', '' );
        if ( isset( $data[ 'open_id' ] ) ) {
            $data[ 'open_id' ] = str_replace( $search, $replace, $data[ 'open_id' ] );
            $where = " openid = '{$data['open_id']}' ";
        }

        switch ( $env ) {
            case 'get':
                //获取记录
                $data[ 'offset' ] += 0;
                if ( $data[ 'offset' ] > 0 ) {
                    $where .= " AND id < {$data['offset']} ";
                }

                $data[ 'limit' ] += 0;
                $sql = "SELECT * FROM wiz_wechat_im_log WHERE {$where} ORDER BY id DESC LIMIT {$data['limit']} ";
                $result = $db->query( $sql );

                return $result;

                break;
            case 'set':
                // 通过$server_id 查询对应客服数据
                if ( $data[ 'type' ] == 'client' ) {
                    $send_type = 1;
                    $server = self::getClient( $server_id, 'server' );
                    if ( !isset( $server[ 'system_id' ] ) ) {
                        $server_id = 0;
                    } else {
                        $server_id = $server[ 'system_id' ];
                    }
                    $open_id = $data[ 'open_id' ];
                } else {
                    $send_type = 0;
                    $server_id = $_SESSION[ 'system_id' ];
                    $client = self::getClient( $data[ 'to' ], 'client' );
                    if ( !isset( $client[ 'open_id' ] ) ) {
                        $open_id = 'unknown';//是否需要处理?
                    } else {
                        $open_id = $client[ 'open_id' ];
                    }
                }
//                $data[ 'data' ] = urlencode( $data[ 'data' ] );//是否真的需要呢?
                $cols = array(
                    'msgType'  => 0,// 这个参数是做什么来的?
                    'sendType' => $send_type,
                    'content'  => $data[ 'data' ],
                    'openId'   => $open_id,
                    'server'   => $server_id,//客服id?
                    'sendAt'   => $data[ 'time' ],
                );
//                var_dump( $cols );
                $db->insert( 'wiz_wechat_im_log' )->cols( $cols )->query();
                break;
        }
    }


}
