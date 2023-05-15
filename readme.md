# Предисловие

:white_check_mark: Данный код не претендует на какой-либо научный прорыв. Не надо придираться к несоблюдению общепринятых стандартов разработки

# Возможности из коробки

:white_check_mark: Работа с сайтами Bitrix в кодировке Windows 1251 и UTF-8

:white_check_mark: Создание сотрудника в Yandex 360 (включая корпоративную почту)

:white_check_mark: Логирование данных о новом сотруднике

:white_check_mark: Деактивация сотрудника

:white_check_mark: Получение списка сотрудников

:white_check_mark: Получение списка подразделений

# Для работы нам понадобится

:white_check_mark: Разобраться с Yandex API 360

:white_check_mark: Токен доступа от приложения https://oauth.yandex.ru/

# Получение токена доступа от приложения у которого есть административный доступ к компании в Yandex 360

:black_square_button: Для доступа к Yandex API 360 подойдет аккаунт не корпоративной почты домена. С корпоративной почтой домена не получится

:black_square_button: Административный доступ к компании https://admin.yandex.ru/

:black_square_button: После того как получите административный доступ, то в ссылке управления компанией "https://admin.yandex.ru/users?uid=\*\*\*" появится ID-компании, который мы будем дальше использовать в константе YANDEX_ORG_ID = '\*\*\*'

:black_square_button: Создаём приложениие https://oauth.yandex.ru/client/new

:black_square_button: https://yandex.ru/dev/api360/doc/concepts/access.html - “1.5 Укажите необходимые права доступа. На текущий момент в API поддерживаются следующие права:”. Права необходимо вбивать в строку поиска и выбирать

:black_square_button: После того как создадите приложение, со всеми перечисленными правами из ссылки выше, необходимо получить токен доступа для управления API. Просто переходим по ссылке https://oauth.yandex.ru/authorize?response_type=token&client_id=ИДЕНТИФИКАТОР_ПРИЛОЖЕНИЯ. Где ИДЕНТИФИКАТОР_ПРИЛОЖЕНИЯ === ClientID из карточки приложения. Нас средиректит на сайт, который мы указали в настройках приложения и в GET-параметре будет access_token=\*\*\*. Это и есть на токен, который в коде мы используем в YANDEX_CLIENT_SECRET = '\*\*\*'

#

:black_square_button: значение YANDEX_CLIENT_SECRET - нельзя хранить под гитом. Как вариант вынесете в приватную константу сайта

:white_check_mark: Все, что я описал выше есть в официальной документации
