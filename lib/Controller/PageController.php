<?php

declare(strict_types=1);

namespace OCA\Daytracker\Controller;

use DateTimeImmutable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller
{
    private const CAT = 'daytracker_categories';
    private const OPT = 'daytracker_options';
    private const ENT = 'daytracker_entries';
    private const TS = 'daytracker_timeslices';
    private const DEFAULTS = [
        ['name' => 'Arbeitsort', 'options' => ['HomeOffice', 'Geschäftsstelle', 'Geschäftsreise mit RK', 'Geschäftsreise ohne RK', 'Urlaub', 'Krankheit', 'keine Arbeit']],
        ['name' => 'Aufenthaltsort', 'options' => ['Berlin', 'Hamburg', '50%/50%', 'sonstiges']],
    ];

    public function __construct(string $appName, IRequest $request, private IDBConnection $db, private ?string $userId)
    {
        parent::__construct($appName, $request);
        $this->userId ??= '';
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        Util::addStyle('daytracker', 'style');
        Util::addScript('daytracker', 'main');
        return new TemplateResponse('daytracker', 'main');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getCatalog(): JSONResponse
    {
        if ($response = $this->requireUser()) return $response;
        $this->ensureInitialData();
        return new JSONResponse(['categories' => $this->loadCategories(), 'timeslices' => $this->loadTimeslices()]);
    }

    #[NoAdminRequired]
    public function saveCatalog(): JSONResponse
    {
        if ($response = $this->requireUser()) return $response;
        $data = $this->readJson();
        if (!is_array($data['categories'] ?? null) || !is_array($data['timeslices'] ?? null)) {
            return new JSONResponse(['error' => 'invalid_catalog'], 400);
        }

        $this->db->beginTransaction();
        try {
            $keptTimesliceIds = $this->saveTimeslices($data['timeslices']);
            $keptCategoryIds = $this->saveCategories($data['categories']);
            $this->deleteMissingCategories($keptCategoryIds);
            $this->deleteMissingTimeslices($keptTimesliceIds);
            $this->db->commit();
        } catch (\InvalidArgumentException $exception) {
            $this->db->rollBack();
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return new JSONResponse(['status' => 'ok', 'categories' => $this->loadCategories(), 'timeslices' => $this->loadTimeslices()]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getDay(string $date): JSONResponse
    {
        if ($response = $this->requireUser()) return $response;
        if (!$this->validDate($date)) return new JSONResponse(['error' => 'invalid_date'], 400);
        $this->ensureInitialData();
        $qb = $this->db->getQueryBuilder();
        $qb->select('e.category_id', 'e.option_id', 'e.timeslice_id', 'e.text_value', 'e.updated_at')
            ->from(self::ENT, 'e')
            ->innerJoin('e', self::CAT, 'c', $qb->expr()->eq('e.category_id', 'c.id'))
            ->innerJoin('e', self::TS, 't', $qb->expr()->eq('e.timeslice_id', 't.id'))
            ->where($qb->expr()->eq('e.user_id', $qb->createNamedParameter($this->userId)))
            ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($this->userId)))
            ->andWhere($qb->expr()->eq('t.user_id', $qb->createNamedParameter($this->userId)))
            ->andWhere($qb->expr()->eq('e.entry_date', $qb->createNamedParameter($date)));
        $result = $qb->executeQuery();
        $entries = [];
        while ($row = $result->fetch()) {
            $entries[] = [
                'category_id' => (int)$row['category_id'],
                'option_id' => $row['option_id'] === null ? null : (int)$row['option_id'],
                'timeslice_id' => (int)$row['timeslice_id'],
                'text_value' => (string)($row['text_value'] ?? ''),
                'updated_at' => (string)$row['updated_at'],
            ];
        }
        $result->closeCursor();
        return new JSONResponse(['date' => $date, 'entries' => $entries]);
    }

    #[NoAdminRequired]
    public function saveDay(string $date): JSONResponse
    {
        if ($response = $this->requireUser()) return $response;
        if (!$this->validDate($date)) return new JSONResponse(['error' => 'invalid_date'], 400);
        $data = $this->readJson();
        $categoryId = (int)($data['category_id'] ?? 0);
        $timesliceId = (int)($data['timeslice_id'] ?? 0);
        $optionId = isset($data['option_id']) && $data['option_id'] !== null ? (int)$data['option_id'] : null;
        $text = $this->cleanMultiline((string)($data['text_value'] ?? ''), 2000);
        if (!$this->owned(self::CAT, $categoryId) || !$this->owned(self::TS, $timesliceId)) return new JSONResponse(['error' => 'invalid_scope'], 400);
        $category = $this->loadCategory($categoryId);
        if ($optionId !== null && !$this->optionInCategory($optionId, $categoryId)) return new JSONResponse(['error' => 'invalid_option'], 400);
        if ($category['input_mode'] === 'options') $text = '';
        if ($category['input_mode'] === 'text') $optionId = null;
        if ($optionId === null && $text === '') return new JSONResponse(['error' => 'empty_value'], 400);

        $entryId = $this->findEntryId($date, $timesliceId, $categoryId);
        $updatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $qb = $this->db->getQueryBuilder();
        $optionParam = $optionId === null ? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL) : $qb->createNamedParameter($optionId, IQueryBuilder::PARAM_INT);
        $textParam = $text === '' ? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL) : $qb->createNamedParameter($text);
        if ($entryId > 0) {
            $qb->update(self::ENT)
                ->set('option_id', $optionParam)
                ->set('text_value', $textParam)
                ->set('updated_at', $qb->createNamedParameter($updatedAt))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
        } else {
            $qb->insert(self::ENT)->values([
                'user_id' => $qb->createNamedParameter($this->userId),
                'entry_date' => $qb->createNamedParameter($date),
                'timeslice_id' => $qb->createNamedParameter($timesliceId, IQueryBuilder::PARAM_INT),
                'category_id' => $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT),
                'option_id' => $optionParam,
                'text_value' => $textParam,
                'updated_at' => $qb->createNamedParameter($updatedAt),
            ]);
        }
        $qb->executeStatement();
        return new JSONResponse(['status' => 'ok', 'entry' => ['category_id' => $categoryId, 'timeslice_id' => $timesliceId, 'option_id' => $optionId, 'text_value' => $text, 'updated_at' => $updatedAt]]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportCsv(): DataDownloadResponse
    {
        $this->ensureInitialData();
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) return new DataDownloadResponse('', 'daytracker-export.csv', 'text/csv; charset=UTF-8');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Datum', 'Zeitscheibe', 'Kategorie', 'Option', 'Freitext', 'letztes Änderungsdatum'], ';');
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias('e.entry_date', 'entry_date')->selectAlias('t.name', 'timeslice_name')
            ->selectAlias('c.name', 'category_name')->selectAlias('o.label', 'option_label')
            ->selectAlias('e.text_value', 'text_value')->selectAlias('e.updated_at', 'updated_at')
            ->from(self::ENT, 'e')->innerJoin('e', self::CAT, 'c', $qb->expr()->eq('e.category_id', 'c.id'))
            ->innerJoin('e', self::TS, 't', $qb->expr()->eq('e.timeslice_id', 't.id'))
            ->leftJoin('e', self::OPT, 'o', $qb->expr()->eq('e.option_id', 'o.id'))
            ->where($qb->expr()->eq('e.user_id', $qb->createNamedParameter($this->userId)))
            ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($this->userId)))
            ->andWhere($qb->expr()->eq('t.user_id', $qb->createNamedParameter($this->userId)))
            ->orderBy('e.entry_date', 'ASC')->addOrderBy('t.sort_order', 'ASC')->addOrderBy('c.sort_order', 'ASC');
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) fputcsv($handle, [$row['entry_date'], $row['timeslice_name'], $row['category_name'], $row['option_label'] ?? '', $row['text_value'] ?? '', $row['updated_at']], ';');
        $result->closeCursor(); rewind($handle); $content = stream_get_contents($handle) ?: ''; fclose($handle);
        return new DataDownloadResponse($content, 'daytracker-export.csv', 'text/csv; charset=UTF-8');
    }

    private function saveTimeslices(array $items): array
    {
        $kept = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) continue;
            $name = $this->clean((string)($item['name'] ?? ''), 128);
            if ($name === '') continue;
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                if (!$this->owned(self::TS, $id)) throw new \InvalidArgumentException('timeslice_not_found');
                $qb = $this->db->getQueryBuilder();
                $qb->update(self::TS)->set('name', $qb->createNamedParameter($name))->set('sort_order', $qb->createNamedParameter($index + 1, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->executeStatement();
            } else {
                $id = $this->insertTimeslice($name, $index + 1);
            }
            $kept[] = $id;
        }
        if ($kept === []) throw new \InvalidArgumentException('at_least_one_timeslice_required');
        return $kept;
    }

    private function saveCategories(array $items): array
    {
        $kept = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) continue;
            $name = $this->clean((string)($item['name'] ?? ''), 128);
            if ($name === '') continue;
            $mode = in_array(($item['input_mode'] ?? ''), ['options', 'text', 'both'], true) ? (string)$item['input_mode'] : 'options';
            $limit = max(0, min(50, (int)($item['dashboard_limit'] ?? 2)));
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                if (!$this->owned(self::CAT, $id)) throw new \InvalidArgumentException('category_not_found');
                $qb = $this->db->getQueryBuilder();
                $qb->update(self::CAT)->set('name', $qb->createNamedParameter($name))->set('sort_order', $qb->createNamedParameter($index + 1, IQueryBuilder::PARAM_INT))
                    ->set('dashboard_limit', $qb->createNamedParameter($limit, IQueryBuilder::PARAM_INT))->set('input_mode', $qb->createNamedParameter($mode))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->executeStatement();
            } else {
                $id = $this->insertCategory($name, $index + 1, $limit, $mode);
            }
            $this->saveOptions($id, $item['options'] ?? []);
            $kept[] = $id;
        }
        return $kept;
    }

    private function saveOptions(int $categoryId, mixed $source): void
    {
        if (!is_array($source)) throw new \InvalidArgumentException('invalid_options');
        $kept = [];
        foreach (array_values($source) as $index => $item) {
            if (!is_array($item)) continue;
            $label = $this->clean((string)($item['label'] ?? ''), 128);
            if ($label === '') continue;
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                if (!$this->optionInCategory($id, $categoryId)) throw new \InvalidArgumentException('option_not_found');
                $qb = $this->db->getQueryBuilder();
                $qb->update(self::OPT)->set('label', $qb->createNamedParameter($label))->set('sort_order', $qb->createNamedParameter($index + 1, IQueryBuilder::PARAM_INT))
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))->executeStatement();
            } else {
                $id = $this->insertOption($categoryId, $label, $index + 1);
            }
            $kept[] = $id;
        }
        foreach ($this->loadOptions($categoryId) as $option) {
            $optionId = (int)$option['id'];
            if (!in_array($optionId, $kept, true)) $this->deleteOption($categoryId, $optionId);
        }
    }

    private function deleteOption(int $categoryId, int $optionId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::ENT)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
            ->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('option_id', $qb->createNamedParameter($optionId, IQueryBuilder::PARAM_INT)))->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::OPT)->where($qb->expr()->eq('id', $qb->createNamedParameter($optionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))->executeStatement();
    }

    private function deleteMissingCategories(array $keptIds): void
    {
        foreach ($this->loadCategories() as $category) {
            $id = (int)$category['id'];
            if (in_array($id, $keptIds, true)) continue;
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::ENT)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
                ->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::OPT)->where($qb->expr()->eq('category_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::CAT)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->executeStatement();
        }
    }

    private function deleteMissingTimeslices(array $keptIds): void
    {
        foreach ($this->loadTimeslices() as $timeslice) {
            $id = (int)$timeslice['id'];
            if (in_array($id, $keptIds, true)) continue;
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::ENT)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
                ->andWhere($qb->expr()->eq('timeslice_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::TS)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->executeStatement();
        }
    }

    private function ensureInitialData(): void
    {
        $timeslices = $this->loadTimeslices();
        if ($timeslices === []) {
            $defaultTimesliceId = $this->insertTimeslice('Ganzer Tag', 1);
        } else {
            $defaultTimesliceId = (int)$timeslices[0]['id'];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::ENT)->set('timeslice_id', $qb->createNamedParameter($defaultTimesliceId, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->andWhere($qb->expr()->isNull('timeslice_id'))->executeStatement();

        if ($this->loadCategories() !== []) return;
        foreach (self::DEFAULTS as $categoryIndex => $definition) {
            $categoryId = $this->insertCategory($definition['name'], $categoryIndex + 1, 2, 'options');
            foreach ($definition['options'] as $optionIndex => $label) $this->insertOption($categoryId, $label, $optionIndex + 1);
        }
    }

    private function loadCategories(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name', 'sort_order', 'dashboard_limit', 'input_mode')->from(self::CAT)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
        $result = $qb->executeQuery(); $items = [];
        while ($row = $result->fetch()) {
            $id = (int)$row['id'];
            $items[] = ['id' => $id, 'name' => (string)$row['name'], 'sort_order' => (int)$row['sort_order'], 'dashboard_limit' => (int)$row['dashboard_limit'], 'input_mode' => (string)$row['input_mode'], 'options' => $this->loadOptions($id)];
        }
        $result->closeCursor(); return $items;
    }

    private function loadCategory(int $id): array
    {
        foreach ($this->loadCategories() as $category) if ((int)$category['id'] === $id) return $category;
        throw new \InvalidArgumentException('category_not_found');
    }

    private function loadOptions(int $categoryId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'label', 'sort_order')->from(self::OPT)
            ->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
        $result = $qb->executeQuery(); $items = [];
        while ($row = $result->fetch()) $items[] = ['id' => (int)$row['id'], 'label' => (string)$row['label'], 'sort_order' => (int)$row['sort_order']];
        $result->closeCursor(); return $items;
    }

    private function loadTimeslices(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name', 'sort_order')->from(self::TS)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
        $result = $qb->executeQuery(); $items = [];
        while ($row = $result->fetch()) $items[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'sort_order' => (int)$row['sort_order']];
        $result->closeCursor(); return $items;
    }

    private function insertCategory(string $name, int $order, int $limit, string $mode): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::CAT)->values(['user_id' => $qb->createNamedParameter($this->userId), 'name' => $qb->createNamedParameter($name), 'sort_order' => $qb->createNamedParameter($order, IQueryBuilder::PARAM_INT), 'dashboard_limit' => $qb->createNamedParameter($limit, IQueryBuilder::PARAM_INT), 'input_mode' => $qb->createNamedParameter($mode)])->executeStatement();
        return $this->findOwnedIdByName(self::CAT, 'name', $name);
    }

    private function insertTimeslice(string $name, int $order): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TS)->values(['user_id' => $qb->createNamedParameter($this->userId), 'name' => $qb->createNamedParameter($name), 'sort_order' => $qb->createNamedParameter($order, IQueryBuilder::PARAM_INT)])->executeStatement();
        return $this->findOwnedIdByName(self::TS, 'name', $name);
    }

    private function insertOption(int $categoryId, string $label, int $order): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::OPT)->values(['category_id' => $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT), 'label' => $qb->createNamedParameter($label), 'sort_order' => $qb->createNamedParameter($order, IQueryBuilder::PARAM_INT)])->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::OPT)->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('label', $qb->createNamedParameter($label)))->setMaxResults(1);
        $row = $qb->executeQuery()->fetch(); return $row ? (int)$row['id'] : 0;
    }

    private function findOwnedIdByName(string $table, string $field, string $value): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($table)->where($qb->expr()->eq($field, $qb->createNamedParameter($value)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->orderBy('id', 'DESC')->setMaxResults(1);
        $row = $qb->executeQuery()->fetch(); return $row ? (int)$row['id'] : 0;
    }

    private function owned(string $table, int $id): bool
    {
        if ($id <= 0) return false;
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->setMaxResults(1);
        return (bool)$qb->executeQuery()->fetch();
    }

    private function optionInCategory(int $optionId, int $categoryId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::OPT)->where($qb->expr()->eq('id', $qb->createNamedParameter($optionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        return (bool)$qb->executeQuery()->fetch();
    }

    private function findEntryId(string $date, int $timesliceId, int $categoryId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::ENT)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
            ->andWhere($qb->expr()->eq('entry_date', $qb->createNamedParameter($date)))
            ->andWhere($qb->expr()->eq('timeslice_id', $qb->createNamedParameter($timesliceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        $row = $qb->executeQuery()->fetch(); return $row ? (int)$row['id'] : 0;
    }

    private function readJson(): array
    {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function clean(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private function cleanMultiline(string $value, int $length): string
    {
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function requireUser(): ?JSONResponse
    {
        return $this->userId === '' ? new JSONResponse(['error' => 'not_authenticated'], 401) : null;
    }
}
