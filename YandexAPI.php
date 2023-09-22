<?php

namespace Pervushin;

use \Bitrix\Main\Loader;
use \Bitrix\Highloadblock\HighloadBlockTable;
use \Bitrix\Main\Text\Encoding;

class YandexAPI
{
    private const CORP_MAILS_TABLE = 'corp_mails';
    private const YANDEX_ORG_ID = YANDEX_ORG_ID;
    private const YANDEX_CLIENT_SECRET = YANDEX_CLIENT_SECRET;
    public const YANDEX_LIMIT = 500;
    public static $lastError;
    public const STOP_LIST_DEACTIVATION_POSTS = STOP_LIST_DEACTIVATION_POSTS;
    public const CORP_DOMEN = 'site.com';
    protected static $departaments = [];
    protected static $ListEmployee = [];
    public static $count = 0;

    /**
     * Добавление сотрудника
     *
     * @param array $employee
     * @return bool
     */
    public static function addEmployee(array $employee): bool
    {
        self::setStiteEnconing($employee);

        self::$lastError = '';

        switch (true) {
            case !in_array($employee['gender'], ['male', 'female']) || strlen(trim($employee['name']['first'])) < 3 || strlen(trim($employee['name']['last'])) < 3 || strlen(trim($employee['nickname'])) < 2 || strlen(trim($employee['password'])) < 8 || trim($employee['departmentId']) < 1 || strlen(trim($employee['company'])) < 3 || strlen(trim($employee['comment'])) < 3:
                self::$lastError = 'Заполните корректно все обязательные данные';
                return false;
            case self::getListEmployee()[mb_strtolower($employee['nickname'])]:
                self::$lastError = 'Пользователь существует';
                return false;
        }

        $employeeUser = [
            'gender' => trim($employee['gender']),
            'name' => $employee['name'],
            'nickname' => mb_strtolower(trim($employee['nickname'])),
            'password' => trim($employee['password']),
            'departmentId' => trim($employee['departmentId'])
        ];

        $res = self::requestYandex($employeeUser, 'POST', 'https://api360.yandex.net/directory/v1/org/{orgId}/users');

        if ($res['id'] > 0) {

            global $USER;
            self::addLogHL(
                [
                    'UF_COMPANY' => htmlspecialchars($employee['company']),
                    'UF_USER' => $USER->getId(),
                    'UF_DATE' => date('d.m.Y H:i:s'),
                    'UF_COMMENTS' => htmlspecialchars($employee['comment']),
                    'UF_GENDER' => $employee['gender'],
                    'UF_LAST_NAME' => $employee['name']['last'],
                    'UF_NAME' => $employee['name']['first'],
                    'UF_EMAIL' => mb_strtolower($employee['nickname']) . '@' . self::CORP_DOMEN
                ]
            );

            self::addLogMessage([
                'mail' => $employee['nickname'] . '@' . self::CORP_DOMEN,
                'password' => $employee['password']
            ]);

            return true;

        } elseif ($res['message'] && $res['code'] > 0) {
            self::$lastError = $res['message'];
            return false;
        }

        self::$lastError = 'Произошла внутренняя ошибка. Попробуйте позже или обратитесь к администратору [code: 1]';
        return false;
    }

    /**
     * Проверка возможности редактиврования почт
     *
     * @param string $mail
     * @return bool
     */
    private static function mailEditEheck(string $mail)
    {
        switch (true) {
            case count(explode('@', $mail)) !== 2:
                self::$lastError = 'Некорректное название почты';
                return false;
            case explode('@', $mail)[1] !== self::CORP_DOMEN:
                self::$lastError = 'Невозможно деактивировать почты, которые не пренадлежат домену';
                return false;
            case in_array(explode('@', $mail)[0], self::STOP_LIST_DEACTIVATION_POSTS):
                self::$lastError = 'Невозможно деактивировать почты, которые запрещены к удалению';
                return false;
            case in_array($mail, self::STOP_LIST_DEACTIVATION_POSTS):
                self::$lastError = 'Данную почту удалять нельзя, т.к. она в списке зарезирвированных';
                return false;
        }
        return true;
    }

    /**
     * Деактивация сотрудника
     *
     * @param string $mail
     * @return bool
     */
    public static function deactivateEmployee(string $mail): bool
    {
        if (!self::mailEditEheck($mail)) {
            return false;
        };

        $nickname = str_replace('@' . self::CORP_DOMEN, '', $mail);

        $empl = self::getListEmployee()[$nickname];

        switch (true) {
            case $empl['id'] > 0 && $empl['isEnabled'] != true:
                self::$lastError = 'Пользователь уже деактивирован';
                return false;
            case $empl['id'] > 0:
                $employee = [
                    'isEnabled' => 0
                ];
                $res = self::requestYandex($employee, 'PATCH', 'https://api360.yandex.net/directory/v1/org/{orgId}/users/' . $empl['id']);
                if ($res['id'] > 0) {
                    return true;
                } elseif ($res['message'] && $res['code'] > 0) {
                    self::$lastError = $res['message'] . ' [code: 7]';
                    return false;
                }
        }

        self::$lastError = 'Произошла внутренняя ошибка. Попробуйте позже или обратитесь к администратору  [code: 2]';
        return false;
    }

    /**
     * Удаление почты сотрудника
     *
     * @param string $mail
     * @return bool
     */
    public static function deleteMail(string $mail): bool
    {
        if (!self::mailEditEheck($mail)) {
            return false;
        };

        $nickname = str_replace('@' . self::CORP_DOMEN, '', $mail);
        $empl = self::getListEmployee()[$nickname];

        switch (true) {
            case $empl['id'] > 0:
                $employee = [];
                if ($empl['email'] === $mail) {
                    $res = self::requestYandex($employee, 'DELETE', 'https://api360.yandex.net/directory/v1/org/{orgId}/users/' . $empl['id']);
                    if ($res['deleted'] == true) {
                        return true;
                    } elseif ($res['message'] && $res['code'] > 0) {
                        self::$lastError = $res['message'] . ' [code: 6]';
                        return false;
                    } else {
                        self::$lastError = 'Произошла внутренняя ошибка. Попробуйте позже или обратитесь к администратору  [code: 5]';
                        return false;
                    }
                } else {
                    self::$lastError = 'Произошла внутренняя ошибка. Попробуйте позже или обратитесь к администратору  [code: 4]';
                    return false;
                }
        }

        self::$lastError = 'Произошла внутренняя ошибка. Попробуйте позже или обратитесь к администратору  [code: 3]';
        return false;
    }

    /**
     * Получение списка сотрудников
     *
     * @return array|bool
     */
    public static function getListEmployee(int $departament = 0)
    {
        self::$count = 0;
        $res = [];
        if (!empty(self::$ListEmployee)) {
            $items = self::$ListEmployee;
        } else {
            $items = self::requestYandex([], 'GET', 'https://api360.yandex.net/directory/v1/org/{orgId}/users?page=1&perPage=' . self::YANDEX_LIMIT);
        }

        if ($items['total'] > 0) {

            $departaments = self::getDepartaments();

            foreach ($items['users'] as $item) {
                self::$count++;
                if ($departament === 0 || $departament === $item['departmentId']) {
                    if (!in_array($item['nickname'], self::STOP_LIST_DEACTIVATION_POSTS)) {
                        $aliases = [];
                        if (!empty($item['aliases'])) {
                            foreach ($item['aliases'] as $alias) {
                                $alias = trim($alias);
                                if (trim($alias)) {
                                    $aliases[mb_strtolower($alias) . '@' . self::CORP_DOMEN] = $alias . '@' . self::CORP_DOMEN;
                                }
                            }
                        }

                        $res[$item['nickname']] = [
                            'id' => $item['id'],
                            'email' => mb_strtolower($item['email']),
                            'nickname' => mb_strtolower($item['nickname']),
                            'name' => $item['name']['first'],
                            'lastName' => $item['name']['last'],
                            'fullName' => $item['name']['last'] . ' ' . $item['name']['first'],
                            'departmentId' => $item['departmentId'],
                            'departmentName' => $departaments[$item['departmentId']]['name'],
                            'aliases' => $aliases,
                            'createdAt' => $item['createdAt'],
                            'isEnabled' => $item['isEnabled']
                        ];
                    }
                }
            }
        } elseif ($items['message'] && $items['code'] > 0) {
            self::$lastError = $items['message'];
            return false;
        }
        return $res;
    }

    /**
     * Получение списка департаментов
     *
     * @return array|bool
     */
    public static function getDepartaments()
    {
        if (!empty(self::$departaments)) {
            return self::$departaments;
        }

        $res = [];
        $resG = self::requestYandex([], 'GET', 'https://api360.yandex.net/directory/v1/org/{orgId}/departments');

        if ($resG['departments']) {
            foreach ($resG['departments'] as $department) {
                $res[$department['id']] = $department;
            }
            self::$departaments = $res;
            return $res;
        } elseif ($resG['message'] && $resG['code'] > 0) {
            self::$lastError = $resG['message'];
            return false;
        }
    }

    /**
     * Непосредственно сам запрос в Yandex
     *
     * @param array $params
     * @param string $method
     * @param string $url
     * @return array
     */
    private static function requestYandex(array $params, string $method, string $url): array
    {
        $url = str_replace('{orgId}', self::YANDEX_ORG_ID, $url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);

        if (in_array($method, ['POST', 'PATCH', 'DELETE'])) {

            $headr = [];
            $headr[] = 'Content-type: application/json';
            $headr[] = 'Authorization: OAuth ' . self::YANDEX_CLIENT_SECRET;

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($params)) {
                self::setUTF8($params);
                $jsonDataEncoded = json_encode($params);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonDataEncoded);
            }
        } else {
            $headr = [];
            $headr[] = 'Content-type: application/json';
            $headr[] = 'Authorization: OAuth ' . self::YANDEX_CLIENT_SECRET;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headr);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $page = curl_exec($ch);

        $result = json_decode($page, true);
        curl_close($ch);

        if (!is_array($result)) {
            $result = [$result];
        }
        self::setStiteEnconing($result);
        return $result;
    }

    /**
     * Логирование создания почты в HL
     *
     * @param array $data
     * @return void
     */
    private static function addLogHL(array $data): void
    {
        if (Loader::includeModule('highloadblock')) {
            $rsData = HighloadBlockTable::getList(
                [
                    'filter' => [
                        'TABLE_NAME' => self::CORP_MAILS_TABLE,
                    ],
                ]
            );
            if ($hldata = $rsData->fetch()) {
                HighloadBlockTable::compileEntity($hldata);
                $hlDataClass = $hldata['NAME'] . 'Table';
                $hlDataClass::add($data);
            }
        }
    }

    /**
     * Чтение лигоривания создания почты в HL
     *
     * @param bool $all
     * @return void
     */
    public static function getLogHL(bool $all = true): array
    {
        $result = [];
        $mails = [];
        if (!$all) {
            $mails = self::getListEmployee(0);
        }

        if (Loader::includeModule('highloadblock')) {
            $rsData = HighloadBlockTable::getList(
                [
                    'filter' => [
                        'TABLE_NAME' => self::CORP_MAILS_TABLE,
                    ],
                ]
            );
            if ($hldata = $rsData->fetch()) {
                HighloadBlockTable::compileEntity($hldata);
                $hlDataClass = $hldata['NAME'] . 'Table';
                $res = $hlDataClass::getList(array(
                        'filter' => [],
                        'select' => array("*"),
                        'order' => array(
                            'ID' => 'desc'
                        ),
                    )
                );
                while ($row = $res->fetch()) {
                    $row['UF_EMAIL'] = mb_strtolower($row['UF_EMAIL']);
                    $nickname = str_replace('@' . self::CORP_DOMEN, '', $row['UF_EMAIL']);
                    //Если уже есть инфа о почте, то пропускаем
                    if ($result[$nickname]) {
                        continue;
                    }
                    $row['nickname'] = $nickname;
                    if (!$all) {
                        $result[$nickname] = $row;
                    } else {
                        if (in_array($mails)) {
                            $result[$nickname] = $row;
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Логирование увежомлением в колокольчик
     *
     * @param array $data
     * @return void
     */
    private static function addLogMessage(array $data, array $users = [1]): void
    {
        if (Loader::includeModule('im')) {
            global $USER;
            foreach ($users as $user) {
                $arMessageFields = array(
                    "TO_USER_ID" => $user,
                    "FROM_USER_ID" => 730,
                    "NOTIFY_TYPE" => IM_NOTIFY_SYSTEM,
                    "NOTIFY_MODULE" => "im",
                    "NOTIFY_TAG" => "IM_CONFIG_NOTICE",
                    "NOTIFY_MESSAGE" => $USER->GetFirstName() . " " . $USER->GetLastName() . " создал почту " . $data['mail'] . ", пароль " . $data['password'] . ". Подробное логирование [URL=/bitrix/admin/highloadblock_rows_list.php?PAGEN_1=1&SIZEN_1=20&ENTITY_ID=33&lang=ru&by=ID&order=desc]тут[/URL]"
                );
                \CIMMessage::Add($arMessageFields);
            }
        }
    }

    /**
     * Кодирование данных в кодировку сайта
     *
     * @param array $data
     * @return void
     */
    public static function setStiteEnconing(array &$data): void
    {
        foreach ($data as $key => &$value) {
            if (!is_array($value)) {
                $data[$key] = Encoding::convertEncodingToCurrent(
                    $data[$key]
                );
            } else {
                self::setStiteEnconing($value);
            }
            unset($key);
            unset($value);
        }
    }

    /**
     * Кодирование данных для запроса в Yandex
     *
     * @param array $data
     * @return void
     */
    private static function setUTF8(array &$data): void
    {
        foreach ($data as $key => &$value) {
            if (!is_array($value)) {

                $isWinCharset = mb_check_encoding($value, "windows-1251");
                if ($isWinCharset) {
                    $value = iconv("windows-1251", "UTF-8", $value);
                }
            } else {
                self::setUTF8($value);
            }
            unset($key);
            unset($value);
        }
    }
}
