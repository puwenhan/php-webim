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
        msg.name = '客服1';
        msg.cid = 1;//先指定id
        msg.avatar = 'http://h.hiphotos.baidu.com/baike/c0%3Dbaike80%2C5%2C5%2C80%2C26/sign=f3d466c53f292df583cea447dd583705/8326cffc1e178a82de3bdc4af303738da877e8d1.jpg';
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
        else if (cmd == 'newUser')
        {
            showNewUser(message);
        }
        else if (cmd == 'fromMsg')
        {
            showNewMsg(message);
        }
        else if (cmd == 'offline')
        {
            var cid = message.fd;
            delUser(cid);
        }
        else
        {

        }
    };

    /**
     * 连接关闭事件
     */
    ws.onclose = function (e) {
        if (confirm("聊天服务器已关闭")) {
            //alert('您已退出聊天室');
            location.href = 'server.html';
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
        sendMsg($('#inputbox').val(), 'text');
        return false;
    } else {
        return true;
    }
};



// 这里有比较多的事件
$('#userlist').change(function(){
    selectUser = $(this).val();
    // alert(selectUser);
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
            div = div + '<div class="userchat" id="history_'+ dataObj.users[i].fd +'" style="display:none"></div>';
        }
    }
    selectUser = (dataObj.users[0].fd != undefined) ? dataObj.users[0].fd : 0;//自动选择一下
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
    console.dir(dataObj);
    for (var i = 0; i < dataObj.history.length; i++) {
        msg = dataObj.history[i]['msg'];
        if (!msg) continue;
        msg['time'] = dataObj.history[i]['time'];
        msg['user'] = dataObj.history[i]['user'];
        if (dataObj.history[i]['type'])
        {
            msg['type'] = dataObj.history[i]['type'];
        }
        msg['channal'] = 3;
        showNewMsg(msg);
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
        // 查询历史聊天记录
    }

}

/**
 * 显示新消息
 */
function showNewMsg(dataObj) {

    var content;
    if (!dataObj.type || dataObj.type == 'text') {
        content = xssFilter(dataObj.data);
    }
    else if (dataObj.type == 'image') {
        var image = eval('(' + dataObj.data + ')');
        content = '<br /><a href="' + image.url + '" target="_blank"><img src="' + image.thumb + '" /></a>';
    }

    var fromId = dataObj.from;
    // var channal = dataObj.channal;

    content = parseXss(content);
    var said = '';
    var time_str;
    var i_said = true;

    if (dataObj.time) {
        time_str = GetDateT(dataObj.time)
    } else {
        time_str = GetDateT()
    }

    $("#msg-template .lim_time").html(time_str);
    $("#msg-template1 .lim_time").html(time_str);
 
    var html = '';
    var to = dataObj.to;


    if (client_id == fromId) {

        html += '<span style="color: green">' + said + ' </span> ';
    }
    else {
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
    $('#userlist option').each(function(){
        $(this).value == userid;
        $(this).remove;
    });
    delete (unreaded[selectUser]);
    $('#unread_'+selectUser).remove();
    $('#history_'+selectUser).remove();
}

function sendMsg(content, type) {
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
    msg.type = type;
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


function selectFace(id) {
    var img = '<img src="/static/img/face/' + id + '.gif" />';
    $("#msg_content").insertAtCaret("#" + id);
    closeChatFace();
}


function showChatFace() {
    $("#chat_face").attr("class", "chat_face chat_face_hover");
    $("#show_face").attr("class", "show_face show_face_hovers");
}

function closeChatFace() {
    $("#chat_face").attr("class", "chat_face");
    $("#show_face").attr("class", "show_face");
}

function toggleFace() {
    $("#chat_face").toggleClass("chat_face_hover");
    $("#show_face").toggleClass("show_face_hovers");
}

