<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\UserTable;

/**
 * Component: company:car.list
*/
class CarListComponent extends \CBitrixComponent
{
    // === КОНФИГУРАЦИЯ ===
    private const IBLOCK_CARS_ID    = 10;
    private const IBLOCK_DRIVERS_ID = 11;
    private const HL_ACCESS_ID      = 1;
    private const HL_BOOKING_ID     = 2;

    // Свойства ИБ
    private const PROP_CATEGORY_CODE = 'CATEGORY_COMFORT';
    private const PROP_DRIVER_CODE   = 'DRIVER_REF';

    // Статусы бронирования
    private const BOOKING_STATUS_CONFIRMED = 'CONFIRMED';

    // Кеш классов HL
    private static array $hlEntities = [];
    private array $errors = [];

    public function executeComponent(): void
    {
        try {
            $this->checkModules();

            $period = $this->getRequestPeriod();
            if (!$period) {
                $this->renderResult();
                return;
            }

            $allowedCategories = $this->getUserAllowedCategories();
            if (empty($allowedCategories)) {
                $this->errors[] = 'Нет доступных категорий авто для вашей должности.';
                $this->renderResult();
                return;
            }

            $busyCarIds = $this->getBusyCarIds($period['FROM'], $period['TO']);

            $cars = $this->getCars($allowedCategories, $busyCarIds);

            if (!empty($cars)) {
                $this->enrichWithDrivers($cars);
            }

            $this->arResult['CARS'] = array_values($cars);
            $this->arResult['PERIOD'] = [
                'FROM' => $period['FROM']->format('d.m.Y H:i'),
                'TO'   => $period['TO']->format('d.m.Y H:i'),
            ];

            $this->renderResult();

        } catch (\Throwable $e) {
            $this->errors[] = 'Произошла системная ошибка. Обратитесь к администратору.';
            $this->renderResult();
        }
    }

    private function checkModules(): void
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('highloadblock')) {
            throw new \RuntimeException('Критические модули системы не найдены.');
        }
    }

    private function getRequestPeriod(): ?array
    {
        $request = Context::getCurrent()->getRequest();
        $strFrom = $request->getQuery("date_from");
        $strTo   = $request->getQuery("date_to");

        if (!$strFrom || !$strTo) {
            $this->errors[] = "Не указан период поездки.";
            return null;
        }

        try {
            $format = 'd.m.Y H:i';

            $from = DateTime::createFromFormat($format, $strFrom);
            $to   = DateTime::createFromFormat($format, $strTo);

            $errorsFrom = DateTime::getLastErrors();
            $errorsTo   = DateTime::getLastErrors();

            if ($errorsFrom['warning_count'] > 0 || $errorsFrom['error_count'] > 0 ||
                $errorsTo['warning_count'] > 0 || $errorsTo['error_count'] > 0) {
                throw new \Exception("Некорректный формат даты или несуществующая дата.");
            }

            if ($from >= $to) {
                $this->errors[] = "Дата окончания должна быть строго позже начала.";
                return null;
            }

            return ['FROM' => $from, 'TO' => $to];

        } catch (\Throwable $e) {
            $this->errors[] = "Ошибка валидации дат: " . $e->getMessage();
            return null;
        }
    }

    private function getUserAllowedCategories(): array
    {
        $userId = CurrentUser::get()->getId();
        if (!$userId) return [];

        $userRow = UserTable::getList([
            'select' => ['WORK_POSITION'],
            'filter' => ['=ID' => $userId],
            'cache'  => ['ttl' => 3600]
        ])->fetch();

        $position = trim((string)$userRow['WORK_POSITION']);
        if ($position === '') return [];

        $entity = $this->getHlEntityClass(self::HL_ACCESS_ID);

        $row = $entity::getList([
            'select' => ['UF_ALLOWED_CATS'],
            'filter' => ['=UF_POSITION' => $position],
            'limit'  => 1
        ])->fetch();

        if (!$row || empty($row['UF_ALLOWED_CATS'])) {
            return [];
        }

        return is_array($row['UF_ALLOWED_CATS'])
            ? $row['UF_ALLOWED_CATS']
            : [$row['UF_ALLOWED_CATS']];
    }

    private function getBusyCarIds(DateTime $from, DateTime $to): array
    {
        $entity = $this->getHlEntityClass(self::HL_BOOKING_ID);

        $res = $entity::getList([
            'select' => ['UF_CAR_ID'],
            'filter' => [
                'LOGIC' => 'AND',
                '<UF_DATE_FROM' => $to,
                '>UF_DATE_TO'   => $from,
                '=UF_STATUS'    => self::BOOKING_STATUS_CONFIRMED
            ]
        ]);

        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['UF_CAR_ID'];
        }

        return array_unique($ids);
    }

    private function getCars(array $categories, array $excludeIds): array
    {
        $categoryFilterKey = 'PROPERTY_' . self::PROP_CATEGORY_CODE . '_VALUE';

        $filter = [
            'IBLOCK_ID' => self::IBLOCK_CARS_ID,
            'ACTIVE'    => 'Y',
            $categoryFilterKey => $categories
        ];

        if (!empty($excludeIds)) {
            $filter['!ID'] = $excludeIds;
        }

        $rs = \CIBlockElement::GetList(
            ['NAME' => 'ASC'],
            $filter,
            false,
            false,
            ['ID', 'NAME', 'PROPERTY_' . self::PROP_CATEGORY_CODE, 'PROPERTY_' . self::PROP_DRIVER_CODE]
        );

        $result = [];
        while ($ob = $rs->GetNextElement()) {
            $fields = $ob->GetFields();
            $props  = $ob->GetProperties();

            $result[$fields['ID']] = [
                'ID'       => (int)$fields['ID'],
                'MODEL'    => $fields['NAME'],
                'CATEGORY' => $props[self::PROP_CATEGORY_CODE]['VALUE'],
                'DRIVER_ID'=> (int)$props[self::PROP_DRIVER_CODE]['VALUE'] ?: null,
                'DRIVER_NAME' => 'Не назначен'
            ];
        }

        return $result;
    }

    private function enrichWithDrivers(array &$cars): void
    {
        $driverIds = array_filter(array_column($cars, 'DRIVER_ID'));
        if (empty($driverIds)) return;

        $rs = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => self::IBLOCK_DRIVERS_ID, 'ID' => array_unique($driverIds)],
            false, false,
            ['ID', 'NAME']
        );

        $map = [];
        while ($row = $rs->Fetch()) {
            $map[$row['ID']] = $row['NAME'];
        }

        foreach ($cars as &$car) {
            if ($car['DRIVER_ID'] && isset($map[$car['DRIVER_ID']])) {
                $car['DRIVER_NAME'] = $map[$car['DRIVER_ID']];
            }
        }
    }

    private function getHlEntityClass(int $hlBlockId): string
    {
        if (!isset(self::$hlEntities[$hlBlockId])) {
            $hl = HighloadBlockTable::getById($hlBlockId)->fetch();
            if (!$hl) {
                throw new \Exception("Конфигурация HL-блока ID:{$hlBlockId} отсутствует.");
            }
            self::$hlEntities[$hlBlockId] = HighloadBlockTable::compileEntity($hl)->getDataClass();
        }
        return self::$hlEntities[$hlBlockId];
    }

    private function renderResult(): void
    {
        $this->arResult['ERRORS'] = $this->errors;
        $this->includeComponentTemplate();
    }
}