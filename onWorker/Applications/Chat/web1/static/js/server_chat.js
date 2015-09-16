var ws = {};
var client_id = 0;
var userlist = {};
var GET = getRequest();
var selectUser = 0;
//未读信息条数 client_id=>条数
var unreaded = {};

$(document).ready(function () {
    //使用原生WebSocket
    if (window.WebSocket || window.MozWebSocket)
    {
        ws = new WebSocket(webim.server);
    }
    //使用flash websocket
    else if (webim.flash_websocket)
    {
        WEB_SOCKET_SWF_LOCATION = "/static/flash-websocket/WebSocketMain.swf";
        $.getScript("/static/flash-websocket/swfobject.js", function () {
            $.getScript("/static/flash-websocket/web_socket.js", function () {
                ws = new WebSocket(webim.server);
            });
        });
    }
    //使用http xhr长轮循
    else
    {
        ws = new Comet(webim.server);
    }
    listenEvent();
});

function listenEvent() {
    /**
     * 连接建立时触发
     */
    ws.onopen = function (e) {
        //连接成功
        console.log("connect webim server success.");
        //发送登录信息
        msg = new Object();
        msg.cmd = 'login_s';
        msg.name = wechat_user.name;
        msg.cid = wechat_user.cid;//先指定客户端session_id
        ws.send($.toJSON(msg));
    };

    //有消息到来时触发
    ws.onmessage = function (e) {
        var message = $.evalJSON(e.data);
        var cmd = message.cmd;
        if (cmd == 'login_s')
        {
            client_id = $.evalJSON(e.data).fd;
            // 获取在线用户列表
            ws.send($.toJSON({cmd : 'getOnline'}));
        }
        else if (cmd == 'getOnline')
        {
            showUserList(message);
        }
        else if (cmd == 'login')
        {
            showNewUser(message);
        }
        else if (cmd == 'message')
        {
            showNewMsg(message);
        }
        else if (cmd == 'offline')
        {
            var client_id = message.fd;
            delUser(client_id);
        }
        else if (cmd == 'history') 
        {
            showHistory(message);
        }else if (cmd == 'ping')
        {
            msg.cmd = 'pong';
            ws.send($.toJSON(msg));
        }
    };

    /**
     * 连接关闭事件
     */
    ws.onclose = function (e) {
        if (confirm("聊天服务器已关闭")) {
            //alert('您已退出聊天室');
            // location.href = 'server.html';
            location.reload();
            // 尝试每几秒重新连接服务器??
        }
    };

    /**
     * 异常事件
     */
    ws.onerror = function (e) {
        alert("异常:" + e.data);
        console.log("onerror");
    };
}

document.onkeydown = function (e) {
    var ev = document.all ? window.event : e;
    if (ev.keyCode == 13) {
        sendMsg($('#inputbox').val());
        return false;
    } else {
        return true;
    }
};



// 这里有比较多的事件
$('#userlist').change(function(){
    selectUser = $(this).val();
    $('.userchat').hide();
    $('#history_' + selectUser).show();
    delete (unreaded[selectUser]);
    $('#unread_'+selectUser).remove();
});


/**
 * 显示所有在线列表
 * @param dataObj
 */
function showUserList(dataObj) {
    var li = '';
    var option = "<option value='' >选择用户</option>";
    var div = "";

    for (var i = 0; i < dataObj.users.length; i++) {

        if (dataObj.users[i].fd != client_id) {
            userlist[dataObj.users[i].fd] = dataObj.users[i];
            option = option + "<option value='" + dataObj.users[i].fd + "' id='user_" + dataObj.users[i].fd + "'>"
                + dataObj.users[i].name + "</option>";

            // 每个在线用户开一个对话框
            var more = '<a id="chat_more_history_'+ dataObj.users[i].fd +'" onclick = "getMore(0, \''+ dataObj.users[i].open_id +'\' )" >查看更多...</a>';
            div = div + '<div class="userchat" id="history_'+ dataObj.users[i].fd +'" style="display:none">'+ more +'</div>';
        }
    }
    // selectUser = (typeof(dataObj.users[0]) != undefined && ()) ? dataObj.users[0].fd : 0;//自动选择一下
    // $('#left-userlist').html(li);
    $('#userlist').html(option);
    $('#notewrap').html(div);
}


/**
 *
 * @param dataObj
 */
function showHistory(dataObj) {
    var msg;
    // console.dir(dataObj);
    // 收到的因为是聊天记录,因此需要反向插入到聊天记录中
    var length = dataObj.data.length;

    if (length == 0) {
        msg = {'isend':1,'nomore':1,'fd':dataObj.fd};
        showHistoryMsg(msg);
        return true;
    };
    for (var i = 0; i < length; i++) {
        msg = dataObj.data[i];
        msg.isend = 1;
        msg.nomore = 1;
        msg.fd = dataObj.fd;
        
        // 如果最后一条,需要给个标志位,增加按钮-查看更多...
        if (i != (length -1) ) {
            msg.isend = 0;
        };
        if (length >= 10) {// 如果每页加载数改变,这个值也应修改!
            msg.nomore = 0;
        };
        showHistoryMsg(msg);
    }
}

/**
 * 当有一个新用户连接上来时
 * @param dataObj
 */
function showNewUser(dataObj) {
    if (!userlist[dataObj.fd]) {
        userlist[dataObj.fd] = dataObj;
        // 追加新用户到select中
        var option = "<option value='" + dataObj.fd + "' id='user_" + dataObj.fd + "'>"
                + dataObj.name + "</option>";
        $('#userlist').append(option);  
        var div = '<div class="userchat" id="history_'+ dataObj.fd +'" style="display:none"></div>';
        $('#notewrap').append(div);
        // 查询历史聊天记录(近10条?暂定)
    }
}

/**
 * 显示新消息
 */
function showNewMsg(dataObj) {

    var content;
    //if (!dataObj.type || dataObj.type == 'text') {
    //    content = xssFilter(dataObj.data);
    //}
    //else if (dataObj.type == 'image') {
    //    var image = eval('(' + dataObj.data + ')');
    //    content = '<br /><a href="' + image.url + '" target="_blank"><img src="' + image.thumb + '" /></a>';
    //}
    var type = dataObj.type;
    var fromId = dataObj.fd;
    // var channal = dataObj.channal;

    content = parseXss(dataObj.data);
    var said = '';
    var time_str;
    var i_said = true;

    if (dataObj.time) {
        time_str = dataObj.time;
    } else {
        time_str = GetDateT();
    }

    $("#msg-template .lim_time").html(time_str);
    $("#msg-template1 .lim_time").html(time_str);
 
    var html = '';
    // 暂时没用
    var to = dataObj.to;


    if (type == 'server') {

        html += '<span style="color: green">' + said + ' </span> ';
    }
    else if(type == 'client'){
        i_said = false;
        html += '<span style="color: orange">' + userlist[fromId].name ;
        html += ':</span> '
    }
    
    html += content + '</span>';

    $("#msg-template .lim_infotip").html(html);
    $("#msg-template1 .lim_dot").html(html);
    
    var $chat_area;
    if (i_said) {
        $chat_area = $("#history_" + to);
        $chat_area.append($("#msg-template1").html());        
    }else{
        $chat_area = $("#history_" + fromId);
        $chat_area.append($("#msg-template").html());
        if (fromId != selectUser) {
            // 加未读红点
            var num = parseInt(unreaded[fromId]==undefined ? 0:unreaded[fromId]) +1;
            var span = '<span id="unread_'+ fromId +'" >'+ userlist[fromId].name +'<span id="barnew_'+ fromId +'" class="barnew">'+num+'</span></span>';
            var $unread = $('#unread_' + fromId);
            
            unreaded[fromId] = num;
            if (num >1) {
                $('#barnew_'+fromId).html(num);
            }else{
                $('#unread').append(span);
            }

        };
    }

    
    $chat_area[0].scrollTop = 1000000;
}

/**
 * 追加旧新消息
 */
function showHistoryMsg(dataObj) {

    var content;
    var said = '';
    var time_str = '';
    var i_said = true;
    var fromid = dataObj.fd;
    var $chat_area;
    var html = '';


    $chat_area = $("#history_" + fromid);

    if (typeof(dataObj.content) != 'undefined') {

        content = xssFilter(dataObj.content);
        content = parseXss(content);
        time_str = dataObj.sendAt;

        $("#msg-template .lim_time").html(time_str);
        $("#msg-template1 .lim_time").html(time_str);
     


        if (dataObj.sendType == 0) {

            html += '<span style="color: green">' + said + ' </span> ';
            html += content + '</span>';
            $("#msg-template1 .lim_dot").html(html);
        }
        else {
            i_said = false;
            html += '<span style="color: orange">' + userlist[fromid].name ;
            html += ':</span> ';
            html += content + '</span>';
            $("#msg-template .lim_infotip").html(html);
        }
        
        
        
        

        if (i_said) {
            $chat_area.prepend($("#msg-template1").html());        
        }else{
            $chat_area.prepend($("#msg-template").html());
        }

    }

    if (dataObj.isend == 1) {
        $("#chat_more_history_"+fromid).remove();
        var more = "";
        if (dataObj.nomore == 0) {
            more = '<a id="chat_more_history_'+ fromid +'" onclick = "getMore('+ dataObj.id +', \''+ userlist[fromid].open_id +'\' ,'+ fromid + ' )" >查看更多...</a>';
        }else{
            more = '没有更多了';
        };
        $chat_area.prepend(more);
        // console.log('endPos!');
    };

    
    // $chat_area[0].scrollTop = 1000000;
}

// 发消息从服务器获取更多聊天记录
function getMore (id , open_id ,fd) {
    // body...
    var msg = {};
    msg.cmd = 'getHistory';
    msg.offset = id;//最后id
    msg.open_id = open_id;
    msg.fd = fd;
    ws.send($.toJSON(msg));
}

function xssFilter(val) {
    val = val.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\x22/g, '&quot;').replace(/\x27/g, '&#39;');
    return val;
}

function parseXss(val) {
    val = val.replace(/#(\d*)/g, '<img src="/static/img/face/$1.gif" />');
    val = val.replace('&amp;', '&');
    return val;
}


function GetDateT(time_stamp) {
    var d;
    d = new Date();

    if (time_stamp) {
        d.setTime(time_stamp * 1000);
    }
    var h, i, s;
    h = d.getHours();
    i = d.getMinutes();
    s = d.getSeconds();

    h = ( h < 10 ) ? '0' + h : h;
    i = ( i < 10 ) ? '0' + i : i;
    s = ( s < 10 ) ? '0' + s : s;
    return h + ":" + i + ":" + s;
}

function getRequest() {
    var url = location.search; // 获取url中"?"符后的字串
    var theRequest = new Object();
    if (url.indexOf("?") != -1) {
        var str = url.substr(1);

        strs = str.split("&");
        for (var i = 0; i < strs.length; i++) {
            var decodeParam = decodeURIComponent(strs[i]);
            var param = decodeParam.split("=");
            theRequest[param[0]] = param[1];
        }

    }
    return theRequest;
}


function delUser(userid) {
    delete (userlist[userid]);
    delete (unreaded[userid]);
    // 直接删除记录不太合适,需要有更复杂的处理
    $('#user_'+userid).remove();
    $('#unread_'+userid).remove();
    $('#history_'+userid).remove();
}

function sendMsg(content) {
    var msg = {};

    if (typeof content == "string") {
        content = content.replace(" ", "&nbsp;");
    }

    if (!content) {
        return false;
    }

    msg.cmd = 'message_s';
    msg.from = client_id;
    msg.to = selectUser;
    msg.channal = 0;
    msg.data = content;
    msg.type = 'server';
    ws.send($.toJSON(msg));

    showNewMsg(msg);
    $('#inputbox').val('');
}

$(document).ready(function () {
    var a = '';
    for (var i = 1; i < 20; i++) {
        a = a + '<a class="face" href="#" onclick="selectFace(' + i + ');return false;"><img src="/static/img/face/' + i + '.gif" /></a>';
    }
    $("#show_face").html(a);
});

(function ($) {
    $.fn.extend({
        insertAtCaret: function (myValue) {
            var $t = $(this)[0];
            if (document.selection) {
                this.focus();
                sel = document.selection.createRange();
                sel.text = myValue;
                this.focus();
            }
            else if ($t.selectionStart || $t.selectionStart == '0') {

                var startPos = $t.selectionStart;
                var endPos = $t.selectionEnd;
                var scrollTop = $t.scrollTop;
                $t.value = $t.value.substring(0, startPos) + myValue + $t.value.substring(endPos, $t.value.length);
                this.focus();
                $t.selectionStart = startPos + myValue.length;
                $t.selectionEnd = startPos + myValue.length;
                $t.scrollTop = scrollTop;
            }
            else {

                this.value += myValue;
                this.focus();
            }
        }
    })
})(jQuery);



