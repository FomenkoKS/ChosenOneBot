<?php

include("Telegram.php");




include("config.php");
require_once('service.php');

$telegram = new Telegram(BOT_ID);
$service = new Service($telegram);

$text = $telegram->Text();
$chat_id = $telegram->ChatID();
$message = $telegram->Message();

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

if ($chat_id > 0) {
    if ($text == "/start") {
        $telegram->sendMessage($service->genMenu());
    }

    if ($text == "/done") {
        $service->cancelWaiting();
        $telegram->sendMessage($service->genMenu());
    }

    
    if ($text == "/campaigns" && $chat_id==SU_ID) {
        $text='';
        foreach($redis->hGetAll('tokens') as $ci=>$t){
            $tg=new Telegram($t);
            $bot=$tg->getMe();
            if($bot['ok']==1){
                $bot=$bot['result'];
                $text.='<b>'.$bot['first_name'].'</b> (@'.$bot['ForeverFox_bot'].') ';
                $text.='Админ: '.$service->getFullname($tg->getChat(['chat_id'=>$ci])['result'])."\r\n";
                $text.= implode("\r\n",$service->getChannelList($t))."\r\n";
                $text.=($redis->sIsMember('campaigns', $t))?'🌕 В процессе':'🌑 Не начат';
                $text.="\r\n\r\n";
            }
        }
        $telegram->sendMessage([
            'chat_id' => SU_ID,
            'text' => $text,
            'disable_web_page_preview'=>true,
            'parse_mode' => 'HTML'
        ]);
    }

    if (preg_match('/\d+:.+/', $text, $matches)) {
        $newTg = new Telegram($matches[0]);
        $bot = $newTg->getMe();
        if ($bot['ok'] == 1 && $redis->hGet('waitToken', $chat_id) == 1) {
            $wh = $newTg->setWebhook(URL);
            if ($wh['ok'] == 1) {
                $redis->hSet('tokens', $chat_id, $matches[0]);
                $redis->hSet('waitToken', $chat_id, 0);
                $telegram->sendMessage($service->genMenu());
            } else {
                $telegram->sendMessage([
                    'chat_id' => $chat_id,
                    'text' => 'Что-то произошло не так, бот не подключён. Уточните токен или отмените с помощью команды /done.'
                ]);
            }

        } elseif ($bot['ok'] == 0) {
            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => "Токен не подошёл. Уточните токен или отмените с помощью команды /done."
            ]);
        }
    }
    
    if ($redis->hGet('waitChannel', $chat_id) == 1) {
        $chat = null;
        if (preg_match('/(@(\w+)|t.me\/(\w+))/', $message['text'], $matches)) {
            $chat = '@' . str_ireplace(['@', 't.me/'], ['', ''], $matches[0]);
        } elseif (isset($message['forward_from_chat'])) {
            $chat = $message['forward_from_chat']['id'];
        }
        if (!is_null($chat)) {
            $token = $redis->hGet('tokens', $chat_id);
            $flag = $service->botIsAdmin($chat, $chat_id);
            if ($flag) {
                $chat = $service->getChatId($chat);
                $redis->sRem('channels:' . $token, $chat);
                $redis->sAdd('channels:' . $token, $chat);
                $text = 'Канал подключен, добавьте следующий канал или нажмите команду /done для прекращения добавления каналов.';
            } else {
                $text = 'У бота нет доступа к написанию сообщений в этом канале. Настройте права и попробуйте ещё раз, или отмените добавление канала с помощью команды /done.';
            }

            $telegram->sendMessage([
                'chat_id' => $chat_id,
                'text' => $text
            ]);
        }
    }
}

if (!is_null($telegram->Callback_Data())) {
    $callback = $telegram->Callback_Query();
    $callbackData = explode(":", $callback['data']);
    $chat_id = $callback['from']['id'];
    switch ($callbackData[0]) {
        case 'setChannel':
            $service->setChannelMsg();
            break;

        case 'delChannel':
            if (count($callbackData) == 1) {
                $token = $redis->hGet('tokens', $chat_id);
                $buttons = [];
                $tg = new Telegram($token);
                foreach ($redis->sMembers('channels:' . $token) as $chat) {
                    $title = $tg->getChat(['chat_id' => $chat])['result']['title'];
                    array_push($buttons, ['callback_data' => 'delChannel:' . $chat, 'text' => $title]);
                }
                $buttons = array_chunk($buttons, 2);
                array_push($buttons,[['text' => '️⛔ Отмена', 'callback_data' => 'reject']]);
                $telegram->editMessageText([
                    'chat_id' => $chat_id,
                    'message_id' => $callback['message']['message_id'],
                    'text' => 'Выберите канал, который хотите убрать из системы.',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => $buttons
                    ])
                ]);
            } else {
                $token = $redis->hGet('tokens', $chat_id);
                $redis->sRem('channels:' . $token, $callbackData[1]);
                $telegram->editMessageText(array_merge($service->genMenu(), ['message_id' => $callback['message']['message_id']]));
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback['id'],
                    'text' => 'Канал удалён из системы'
                ]);
            }
            break;

        case 'setToken':
            $service->setTokenMsg();
            break;

        case 'endCampaign':
            $telegram->editMessageText([
                'chat_id' => $callback['from']['id'],
                'message_id' => $callback['message']['message_id'],
                'text' => 'Вы уверены, что хотите завершить конкурс?',
                'reply_markup' => $telegram->buildInlineKeyBoard(
                    [
                        [['text' => '✅ Подтвердить', 'callback_data' => 'confirm:endCampaign']],
                        [['text' => '️⛔ Отменить завершение', 'callback_data' => 'reject']]
                    ]
                )
            ]);
            break;

        case 'eraseMembers':
            $telegram->editMessageText([
                'chat_id' => $callback['from']['id'],
                'message_id' => $callback['message']['message_id'],
                'text' => 'Вы уверены, что хотите удалить участников?',
                'reply_markup' => $telegram->buildInlineKeyBoard(
                        [
                            [['text' => '✅ Подтвердить', 'callback_data' => 'confirm:eraseMembers']],
                            [['text' => '️⛔ Отменить удаление', 'callback_data' => 'reject']]
                        ]
                    )
            ]);
        break;

        case 'confirm':
            $token = $redis->hGet('tokens', $chat_id);
            switch ($callbackData[1]){
                
                case 'endCampaign':
                    $token = $redis->hGet('tokens', $chat_id);
                    $winners=$redis->sMembers('winners:' . $token);
                    
                    if(count($winners)>1){
                        $text="\r\n\r\nПобедители:\r\n";
                        foreach($winners as $i=>$v) $text.="<b>".($i+1).")</b> ".$service->getFullname(unserialize($v))."\r\n";
                    } else $text='Победитель: '.$service->getFullname(unserialize($winners[0]));
                    $tg=new Telegram($token);
                    foreach ($redis->sMembers('channels:' . $token) as $chat) {
                        $tg->sendMessage([ 
                            'chat_id' => $chat,
                            'text' => 'Конкурс завершён. '.$text,
                            'parse_mode' => 'HTML'
                            ]);
                    }
                    $telegram->sendMessage([ 
                        'chat_id' => $chat_id,
                        'text' => 'Конкурс завершён. '.$text."\r\nЕсли вам понравился бот, то вы всегда можете <a href='https://yasobe.ru/na/anfisa'>кинуть донат на развитие Анфисы</a>.\r\nОбратная связь: @Anfisa_Feedback_Bot.",
                        'parse_mode' => 'HTML'
                    ]);
                    $redis->sRem('campaigns', $token);                             
                    $redis->delete('winners:' . $token);
                    $redis->delete('members:' . $token);
                    $telegram->editMessageText(array_merge($service->genMenu(), ['message_id' => $callback['message']['message_id']]));
                case 'eraseMembers':
                    $redis->delete('members:' . $token);
                    $telegram->editMessageText(array_merge($service->genMenu(), ['message_id' => $callback['message']['message_id']]));
                break;
            }
             
            break;

        case 'showMembers':
            if(!isset($callbackData[1])){
                $telegram->sendMessage($service->genMemberList());
            }else{
                $page=$callbackData[1];
                $telegram->editMessageText(array_merge($service->genMemberList($page), ['message_id' => $callback['message']['message_id']]));
            }
        break;

        case 'getWinner':
            $token = $redis->hGet('tokens', $chat_id);
            $flag=false;

            while($redis->sCard('members:' .  $token)>0 && !$flag){
                $member=unserialize($redis->sPop('members:' . $token));
                $result=$service->conditionsComplied($token,$member['id']);
                $user=$service->getFullname($member);
                if($result==0) {
                    $flag=true;
                    $redis->sAdd('winners:'.$token,serialize($member));
                }else{
                    $telegram->answerCallbackQuery([
                        'callback_query_id' => $callback['id'],
                        'text' => "Пользователь $user не подходит под условия участия. Он был удалён.",
                        'show_alert'=>true
                    ]);
                }
            }
            if($flag)$telegram->sendMessage(['chat_id'=>$chat_id,'text'=>"Определён победитель $user.",'parse_mode' => 'HTML']);
            
        case 'reject':
        case 'refresh':
            $telegram->editMessageText(array_merge($service->genMenu(), ['message_id' => $callback['message']['message_id']]));
            break;
        case 'startCampaign':
            $token = $redis->hGet('tokens', $chat_id);
            $tg = new Telegram($token);
            $flag = 1;
            $chats = [];
            foreach ($redis->sMembers('channels:' . $token) as $chat) {
                $admins = $tg->getChatAdministrators(['chat_id' => $chat]);
                ($service->botIsAdmin($chat, $chat_id)) ? array_push($chats, $chat) : $flag = 0;
            }
            if ($flag == 1) {

                if ($redis->sIsMember('campaigns', $token) != 1) {
                    $redis->sAdd('campaigns', $token);
                    foreach ($redis->sMembers('channels:' . $token) as $chat) {
                        $tg->sendMessage($service->genPost($chat, $token));
                        ($service->botIsAdmin($chat, $chat_id)) ? array_push($chats, $chat) : $flag = 0;
                    }

                    $telegram->answerCallbackQuery([
                        'callback_query_id' => $callback['id'],
                        'text' => 'Сообщения с объявлением направлены в каналы'
                    ]);
                    
                    $telegram->editMessageText(array_merge($service->genMenu(), ['message_id' => $callback['message']['message_id']]));
                }
            } else {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback['id'],
                    'text' => 'Бот не добавлен в качестве администратора в один из каналов'
                ]);
            }

            break;
        case 'accept':
            $token = 0;
            foreach ($redis->sMembers('campaigns') as $k) if (explode(':', $k)[0] == $callbackData[1]) $token = $k;

            if ($token != 0) {
                $user=$callback['from'];

                if($redis->sIsMember('members:' . $token, serialize($user))){
                    $text='Вы уже участвуете в конкурсе.';
                    $status=0;
                }else{
                    $status=$service->conditionsComplied($token,$user['id']);
                    $text='Спасибо за участие.';
                    switch($status){
                        case 1:
                            $text.="\r\nНе забудьте вступить в остальные каналы.";
                        break;
                        case 2:
                            $text='Вы состоите в администраторах одного из каналов';
                        break;
                    }
                    
                }        
                $tg = new Telegram($token);
                $tg->answerCallbackQuery([
                    'callback_query_id' => $callback['id'],
                    'text' => $text,
                    'show_alert'=>true
                ]);

                if($status!=2) $redis->sAdd('members:' . $token, serialize($callback['from']));
            }
            $tg->editMessageText(array_merge($service->genPost($callback['message']['chat']['id'], $token), ['message_id' => $callback['message']['message_id']]));
            break;
    }
}

$redis->close();