<?php

namespace YandexAPI;

use \Bitrix\Main\Loader;
use \Bitrix\Highloadblock\HighloadBlockTable;
use \Bitrix\Main\Text\Encoding;

class YandexAPI
{
    const CORP_MAILS_TABLE = 'corp_mails';
    private const YANDEX_ORG_ID = '***';
    private const YANDEX_CLIENT_SECRET = '***';
    const YANDEX_LIMIT = 500;
    static string $lastError;
    const STOP_LIST_DEACTIVATION_MAILS = [];
    const CORP_DOMEN = 'site.ru';

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
            case self::getListEmployee()[$employee['nickname']]:
                self::$lastError = 'Пользователь существует';
                return false;
        }

        $employeeUser = [
            'gender' => trim($employee['gender']),
            'name' => $employee['name'],
            'nickname' => trim($employee['nickname']),
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
                    'UF_EMAIL' => $employee['nickname'] . '@' . self::CORP_DOMEN
                ]
            );

            return true;

        } elseif ($res['message'] && $res['code'] > 0) {
            self::$lastError = $res['message'];
            return false;
        }

        self::$lastError = 'Произошла внутренняя ошибка. Попробуйте позже или обратитесь к администратору [code: 1]';
        return false;
    }

    /**
     * Деактивация сотрудника
     *
     * @param string $mail
     * @return bool
     */
    static function deactivateEmployee(string $mail): bool
    {
        switch (true) {
            case count(explode('@', $mail)) !== 2:
                self::$lastError = 'Некорректное название почты';
                return false;
            case explode('@', $mail)[1] !== self::CORP_DOMEN:
                self::$lastError = 'Невозможно деактивировать почты, которые не пренадлежат домену';
                return false;
            case in_array(explode('@', $mail)[0], self::STOP_LIST_DEACTIVATION_MAILS):
                self::$lastError = 'Невозможно деактивировать почты, которые запрещены к удалению';
                return false;
            case in_array($mail, self::STOP_LIST_DEACTIVATION_MAILS):
                self::$lastError = 'Данную почту удалять нельзя, т.к. она в списке зарезирвированных';
                return false;
        }

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
                    self::$lastError = $res['message'];
                    return false;
                }
        }

        self::$lastError = 'Произошла внутренняя ошибка. Попробуйте позже или обратитесь к администратору  [code: 2]';
        return false;
    }

    /**
     * Получение списка сотрудников
     *
     * @return array
     */
    public static function getListEmployee(): array
    {
        $res = [];
        $items = self::requestYandex([], 'GET', 'https://api360.yandex.net/directory/v1/org/{orgId}/users?page=1&perPage=' . self::YANDEX_LIMIT);
        if ($items['total'] > 0) {
            foreach ($items['users'] as $item) {
                if (!in_array($item['nickname'], self::STOP_LIST_DEACTIVATION_MAILS)) {
                    $res[$item['nickname']] = $item;
                }
            }
        }
        return $res;
    }

    /**
     * Получение списка департаментов
     *
     * @return bool
     */
    public static function getDepartaments(): array
    {
        $res = [];
        $resG = self::requestYandex([], 'GET', 'https://api360.yandex.net/directory/v1/org/{orgId}/departments');
        foreach ($resG['departments'] as $department) {
            $res[$department['id']] = $department;
        }
        return $res;
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

        if (in_array($method, ['POST', 'PATCH'])) {

            $headr = [];
            $headr[] = 'Content-type: application/json';
            $headr[] = 'Authorization: OAuth ' . self::YANDEX_CLIENT_SECRET;

            self::setUTF8($params);

            $jsonDataEncoded = json_encode($params);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonDataEncoded);

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
