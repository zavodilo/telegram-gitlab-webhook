<?php

$telegramToken = "telegram-token";
$telegramChatId = "telegram-chat-id";
$gitlabToken = "gitlab-token";

// Проверяем гитлаб токен
if (!(isset($_SERVER['HTTP_X_GITLAB_TOKEN'])
    && $_SERVER['HTTP_X_GITLAB_TOKEN'] == $gitlabToken
    && isset($_SERVER['HTTP_X_GITLAB_EVENT']))) {
    return;
}
// Получаем массив данных из гитлаба
$gitlabData = json_decode(file_get_contents('php://input'), true);

// Проверем, что пришли нужные нам данные
if (! (($_SERVER['HTTP_X_GITLAB_EVENT'] == "Push Hook" && $gitlabData["ref"] == "refs/heads/master" && $gitlabData["total_commits_count"] != 0) ||
    ($_SERVER['HTTP_X_GITLAB_EVENT'] == "Merge Request Hook" && ($gitlabData["object_attributes"]["state"] != "merged" || $gitlabData["object_attributes"]["state"] != "production") && $gitlabData["object_attributes"]["action"] == "open") ||
    ($_SERVER['HTTP_X_GITLAB_EVENT'] == "Note Hook") ||
    ($_SERVER['HTTP_X_GITLAB_EVENT'] == "Pipeline Hook")
)) {

    return;
}

// Сообщение для отправки в телеграм
$message = "";

// Добавлены коммиты в ветку
if ($_SERVER['HTTP_X_GITLAB_EVENT'] == "Push Hook") {
    $commits = $gitlabData["commits"];

    foreach ($commits as $commit) {
        $message .= '<b>'
            .$commit["author"]["name"]
            .':</b>'
            .PHP_EOL
            .$commit["message"]
            .PHP_EOL
            .'<a href="'
            .$commit["url"]
            .'">Подробнее...</a>'
            .PHP_EOL;
    }
}

// Создан Merge Request
if ($_SERVER['HTTP_X_GITLAB_EVENT'] == "Merge Request Hook") {
    $merge = $gitlabData["object_attributes"];
    $message .= '<b>'
        .$merge["last_commit"]["author"]["name"]
        .':</b>'
        .PHP_EOL
        .'Запрос на слияние: '
        .$merge["iid"]
        .PHP_EOL
        .'<i>'
        .$gitlabData["object_attributes"]["source_branch"]
        .' в︎ '
        .$gitlabData["object_attributes"]["target_branch"]
        .'</i>'
        .PHP_EOL
        .$merge["title"]
        .PHP_EOL
        .'<a href="'
        .$gitlabData["repository"]["homepage"]
        .'/merge_requests/'
        .$merge["iid"]
        .'">Подробнее...</a>';
}

// Сообщение о комментарии в Merge Request
if ($_SERVER['HTTP_X_GITLAB_EVENT'] == "Note Hook"
    && ! empty($gitlabData['object_attributes'])) {
    $message .= '<b>'.$gitlabData["user"]["name"]
        .':</b>'
        .PHP_EOL
        .$gitlabData["title"]
        .PHP_EOL
        .'Комментарий:'
        .PHP_EOL
        .$gitlabData['object_attributes']['note']
        .PHP_EOL
        .'<a href="'
        .$gitlabData["object_attributes"]["url"]
        .'">Подробнее...</a>';
}

// Статусы которые игнорируем
$notStatus = [
    'success', 'running', 'pending'
];

// Создаем сообщение о неверном статусе Pipeline
if ($_SERVER['HTTP_X_GITLAB_EVENT'] == "Pipeline Hook" && ! empty($gitlabData['object_attributes']) && ! empty($gitlabData['object_attributes']['status']) && ! in_array($gitlabData['object_attributes']['status'], $notStatus)) {
    $message .= '<b>'
        .$gitlabData["user"]["name"]
        .':</b>'
        .PHP_EOL
        .$gitlabData["object_attributes"]["ref"]
        .PHP_EOL
        .'Статус:'
        .PHP_EOL
        .$gitlabData["object_attributes"]["status"];
}

// Если сообщение пустое, то нечего отправлять
if (empty($message)) {
    return;
}

// Добавляем проект
if (! empty($gitlabData["project"]["name"])) {
    $message = 'Проект: '
        .$gitlabData["project"]["name"]
        .PHP_EOL
        .$message;
}


// Отправка данных в телеграм
$telegramData = [];
$telegramData["chat_id"] = $telegramChatId;
$telegramData["text"] = $message;
$telegramData["parse_mode"] = 'HTML';
$telegramData["disable_web_page_preview"] = true;
$telegramData["disable_notification"] = true;

$curlHandle = curl_init('https://api.telegram.org/bot'.$telegramToken.'/sendMessage');
curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($curlHandle, CURLOPT_TIMEOUT, 60);
curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($telegramData));
curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
curl_exec($curlHandle);


