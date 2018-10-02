app/routes.php
app/routes/api/chats.php
app/cli/mvc/controllers/chats/messages_controller.php
app/cli/mvc/tasks/chats/messages.php
app/libs/chats/amojo/storage/chats.php                               get_by_chat_id
app/libs/chats/amojo/api/amojo_api_client.php

--------------------------------

frontend/js/lib/interface/amojo/api.js
frontend/js/lib/components/base/inbox/chats/base_chat.js

--------------------------------
AMOCRM bitrix/templates/single_page_app/header.php

amojo_chats
amojo_chats_context

-----------------------------------------------------------------
'/private/api/v2/json/chats/session?entity=chats&action=session'
app/libs/mvc/controllers/api/v2/chats/chats_controller.php
Создание нового токена для пользователя amoJo  function action_session()
-----------------------------------------------------------------
'/private/api/v2/json/chats/bots/connect?entity=chats%2Fbots&action=connect'
app/libs/mvc/controllers/api/v2/chats/bots/bots_controller.php  function action_connect(

||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||

CORE
---------------------------------
---------------------------------

amolyakov-amojo-handle_messages
===============================
app/cli/mvc/controllers/chats/messages_controller.php            function handle_messages

app/cli/mvc/models/chats/messages_handler.php                    function handle_message


amolyakov-amojo-handle_execute
==============================
app/cli/mvc/controllers/chats/salesbot_controller.php            function handle_execute


amolyakov-amojo-handle_salesbot
===============================
app/cli/mvc/controllers/chats/messages_controller.php            function handle_salesbot

app/cli/mvc/models/chats/salesbot/salesbot.php                   function process_reply
app/cli/mvc/models/chats/salesbot/salesbot.php                   function bot_controller
app/cli/mvc/models/chats/salesbot/salesbot.php                   function send_message

app/libs/chats/amojo/api/amojo_api_client.php                    function send_bot_message

------------------------------------------------------------------------------
пришлите телефон и email
-------------------
ответ - телефон

app/cli/mvc/controllers/chats/messages_controller.php            function handle_messages

app/cli/mvc/models/chats/messages_handler.php                    function handle_message
                                                                 function handle_external_message
                                                                 function push_contact_notification

app/libs/notifications/helpers/notify_service.php                function send_background

app/libs/chats/amojo/helpers/amojo_talks_helper.php              function handle_talk

app/cli/mvc/models/chats/salesbot/salesbot.php                   function process_reply

app/cli/mvc/models/chats/salesbot/salesbot.php                   function bot_controller




привет, решили расширить реализцию функционала быстрых ответов для фб. не знаю имеет ли ли это значение на стороне amojo. формат отсылаемых в amojo данных остается тем же, но использовать функционал можно будет для любого обработчика SHOW типа "text". в доке поправил

возможность использования - обработчик SHOW типа “text”
досылается параметр quick_replies на эндпоинт amojo /bots/xxxxxxxxxxxxxxx/messages в формате:
