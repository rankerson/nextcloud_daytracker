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
    private const CATEGORIES = 'daytracker_categories';
    private const OPTIONS = 'daytracker_options';
    private const ENTRIES = 'daytracker_entries';
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
        $this->ensureDefaults();
        return new JSONResponse(['categories' => $this->loadCatalog()]);
    }

    #[NoAdminRequired]
    public function saveCatalog(): JSONResponse
    {
        if ($response = $this->requireUser()) return $response;
        $payload = $this->readJsonBody();
        if (!isset($payload['categories']) || !is_array($payload['categories'])) {
            return new JSONResponse(['error' => 'invalid_catalog_payload'], 400);
        }

        $this->db->beginTransaction();
        try {
            foreach (array_values($payload['categories']) as $index => $categoryData) {
                if (!is_array($categoryData)) continue;
                $name = $this->clean((string)($categoryData['name'] ?? ''), 128);
                if ($name === '') continue;
                $limit = max(0, min(50, (int)($categoryData['dashboard_limit'] ?? 2)));
                $categoryId = (int)($categoryData['id'] ?? 0);
                if ($categoryId > 0) {
                    if (!$this->categoryBelongsToUser($categoryId)) {
                        throw new \InvalidArgumentException('category_not_found');
                    }
                    $this->assertCategoryNameAvailable($name, $categoryId);
                    $this->updateCategory($categoryId, $name, $index + 1, $limit);
                } else {
                    $this->assertCategoryNameAvailable($name, 0);
                    $categoryId = $this->insertCategory($name, $index + 1, $limit);
                }
                $this->saveOptionsById($categoryId, $categoryData['options'] ?? []);
            }
            $this->db->commit();
        } catch (\InvalidArgumentException $exception) {
            $this->db->rollBack();
            return new JSONResponse(['error' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $this->ensureDefaults();
        return new JSONResponse(['status' => 'ok', 'categories' => $this->loadCatalog()]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getDay(string $date): JSONResponse
    {
        if ($response = $this->requireUser()) return $response;
        if (!$this->validDate($date)) return new JSONResponse(['error' => 'invalid_date'], 400);
        $this->ensureDefaults();
        return new JSONResponse(['date' => $date, 'entries' => $this->loadEntries($date)]);
    }

    #[NoAdminRequired]
    public function saveDay(string $date): JSONResponse
    {
        if ($response = $this->requireUser()) return $response;
        if (!$this->validDate($date)) return new JSONResponse(['error' => 'invalid_date'], 400);
        $payload = $this->readJsonBody();
        $categoryId = (int)($payload['category_id'] ?? 0);
        $optionId = (int)($payload['option_id'] ?? 0);
        if (!$this->categoryBelongsToUser($categoryId) || !$this->optionBelongsToCategory($optionId, $categoryId)) {
            return new JSONResponse(['error' => 'invalid_selection'], 400);
        }
        $updatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $entryId = $this->findEntryId($date, $categoryId);
        $qb = $this->db->getQueryBuilder();
        if ($entryId > 0) {
            $qb->update(self::ENTRIES)
                ->set('option_id', $qb->createNamedParameter($optionId, IQueryBuilder::PARAM_INT))
                ->set('updated_at', $qb->createNamedParameter($updatedAt))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
        } else {
            $qb->insert(self::ENTRIES)->values([
                'user_id' => $qb->createNamedParameter($this->userId),
                'entry_date' => $qb->createNamedParameter($date),
                'category_id' => $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT),
                'option_id' => $qb->createNamedParameter($optionId, IQueryBuilder::PARAM_INT),
                'updated_at' => $qb->createNamedParameter($updatedAt),
            ]);
        }
        $qb->executeStatement();
        return new JSONResponse(['status' => 'ok', 'entry' => ['category_id' => $categoryId, 'option_id' => $optionId, 'updated_at' => $updatedAt]]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportCsv(): DataDownloadResponse
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) return new DataDownloadResponse('', 'daytracker-export.csv', 'text/csv; charset=UTF-8');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Datum', 'Kategorie', 'Option', 'letztes Änderungsdatum'], ';');
        if ($this->userId !== '') {
            $qb = $this->db->getQueryBuilder();
            $qb->selectAlias('e.entry_date', 'entry_date')->selectAlias('c.name', 'category_name')
                ->selectAlias('o.label', 'option_label')->selectAlias('e.updated_at', 'updated_at')
                ->from(self::ENTRIES, 'e')->innerJoin('e', self::CATEGORIES, 'c', $qb->expr()->eq('e.category_id', 'c.id'))
                ->innerJoin('e', self::OPTIONS, 'o', $qb->expr()->eq('e.option_id', 'o.id'))
                ->where($qb->expr()->eq('e.user_id', $qb->createNamedParameter($this->userId)))
                ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($this->userId)))
                ->orderBy('e.entry_date', 'ASC')->addOrderBy('c.sort_order', 'ASC');
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) fputcsv($handle, [$row['entry_date'], $row['category_name'], $row['option_label'], $row['updated_at']], ';');
            $result->closeCursor();
        }
        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);
        return new DataDownloadResponse($content, 'daytracker-export.csv', 'text/csv; charset=UTF-8');
    }

    private function saveOptionsById(int $categoryId, mixed $source): void
    {
        if (!is_array($source)) throw new \InvalidArgumentException('invalid_options');
        $usedIds = [];
        foreach (array_values($source) as $index => $optionData) {
            if (!is_array($optionData)) throw new \InvalidArgumentException('invalid_option');
            $label = $this->clean((string)($optionData['label'] ?? ''), 128);
            if ($label === '') continue;
            $optionId = (int)($optionData['id'] ?? 0);
            if ($optionId > 0) {
                if (!$this->optionBelongsToCategory($optionId, $categoryId)) throw new \InvalidArgumentException('option_not_found');
                $this->assertOptionLabelAvailable($categoryId, $label, $optionId);
                $this->updateOption($optionId, $label, $index + 1);
            } else {
                $this->assertOptionLabelAvailable($categoryId, $label, 0);
                $optionId = $this->insertOption($categoryId, $label, $index + 1);
            }
            $usedIds[] = $optionId;
        }
        foreach ($this->loadOptions($categoryId) as $option) {
            $optionId = (int)$option['id'];
            if (!in_array($optionId, $usedIds, true) && $this->countOptionEntries($optionId) === 0) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete(self::OPTIONS)->where($qb->expr()->eq('id', $qb->createNamedParameter($optionId, IQueryBuilder::PARAM_INT)))->executeStatement();
            }
        }
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $categoryIndex => $definition) {
            $categoryId = $this->findCategoryByName($definition['name']);
            if ($categoryId === 0) $categoryId = $this->insertCategory($definition['name'], $categoryIndex + 1, 2);
            foreach ($definition['options'] as $optionIndex => $label) {
                if ($this->findOptionByLabel($categoryId, $label) === 0) $this->insertOption($categoryId, $label, $optionIndex + 1);
            }
        }
    }

    private function loadCatalog(): array
    {
        $items = [];
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name', 'sort_order', 'dashboard_limit')->from(self::CATEGORIES)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
            ->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $id = (int)$row['id'];
            $items[] = ['id' => $id, 'name' => (string)$row['name'], 'sort_order' => (int)$row['sort_order'], 'dashboard_limit' => (int)$row['dashboard_limit'], 'options' => $this->loadOptions($id)];
        }
        $result->closeCursor();
        return $items;
    }

    private function loadOptions(int $categoryId): array
    {
        $items = [];
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'label', 'sort_order')->from(self::OPTIONS)
            ->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) $items[] = ['id' => (int)$row['id'], 'label' => (string)$row['label'], 'sort_order' => (int)$row['sort_order']];
        $result->closeCursor();
        return $items;
    }

    private function loadEntries(string $date): array
    {
        $items = [];
        $qb = $this->db->getQueryBuilder();
        $qb->select('e.category_id', 'e.option_id', 'e.updated_at')->from(self::ENTRIES, 'e')
            ->innerJoin('e', self::CATEGORIES, 'c', $qb->expr()->eq('e.category_id', 'c.id'))
            ->where($qb->expr()->eq('e.user_id', $qb->createNamedParameter($this->userId)))
            ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($this->userId)))
            ->andWhere($qb->expr()->eq('e.entry_date', $qb->createNamedParameter($date)))->orderBy('c.sort_order', 'ASC');
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) $items[] = ['category_id' => (int)$row['category_id'], 'option_id' => (int)$row['option_id'], 'updated_at' => (string)$row['updated_at']];
        $result->closeCursor();
        return $items;
    }

    private function insertCategory(string $name, int $order, int $limit): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::CATEGORIES)->values(['user_id' => $qb->createNamedParameter($this->userId), 'name' => $qb->createNamedParameter($name), 'sort_order' => $qb->createNamedParameter($order, IQueryBuilder::PARAM_INT), 'dashboard_limit' => $qb->createNamedParameter($limit, IQueryBuilder::PARAM_INT)])->executeStatement();
        return $this->findCategoryByName($name);
    }

    private function updateCategory(int $id, string $name, int $order, int $limit): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::CATEGORIES)->set('name', $qb->createNamedParameter($name))->set('sort_order', $qb->createNamedParameter($order, IQueryBuilder::PARAM_INT))->set('dashboard_limit', $qb->createNamedParameter($limit, IQueryBuilder::PARAM_INT))->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->executeStatement();
    }

    private function insertOption(int $categoryId, string $label, int $order): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::OPTIONS)->values(['category_id' => $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT), 'label' => $qb->createNamedParameter($label), 'sort_order' => $qb->createNamedParameter($order, IQueryBuilder::PARAM_INT)])->executeStatement();
        return $this->findOptionByLabel($categoryId, $label);
    }

    private function updateOption(int $id, string $label, int $order): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::OPTIONS)->set('label', $qb->createNamedParameter($label))->set('sort_order', $qb->createNamedParameter($order, IQueryBuilder::PARAM_INT))->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
    }

    private function assertCategoryNameAvailable(string $name, int $exceptId): void
    {
        $found = $this->findCategoryByName($name);
        if ($found > 0 && $found !== $exceptId) throw new \InvalidArgumentException('category_name_exists');
    }

    private function assertOptionLabelAvailable(int $categoryId, string $label, int $exceptId): void
    {
        $found = $this->findOptionByLabel($categoryId, $label);
        if ($found > 0 && $found !== $exceptId) throw new \InvalidArgumentException('option_label_exists');
    }

    private function findCategoryByName(string $name): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::CATEGORIES)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)))->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['id'] : 0;
    }

    private function findOptionByLabel(int $categoryId, string $label): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::OPTIONS)->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('label', $qb->createNamedParameter($label)))->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['id'] : 0;
    }

    private function categoryBelongsToUser(int $id): bool
    {
        if ($id <= 0) return false;
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::CATEGORIES)->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->setMaxResults(1);
        return (bool)$qb->executeQuery()->fetch();
    }

    private function optionBelongsToCategory(int $optionId, int $categoryId): bool
    {
        if ($optionId <= 0) return false;
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::OPTIONS)->where($qb->expr()->eq('id', $qb->createNamedParameter($optionId, IQueryBuilder::PARAM_INT)))->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        return (bool)$qb->executeQuery()->fetch();
    }

    private function findEntryId(string $date, int $categoryId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from(self::ENTRIES)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->andWhere($qb->expr()->eq('entry_date', $qb->createNamedParameter($date)))->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['id'] : 0;
    }

    private function countOptionEntries(int $optionId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->func()->count('*'), 'entry_count')->from(self::ENTRIES)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))->andWhere($qb->expr()->eq('option_id', $qb->createNamedParameter($optionId, IQueryBuilder::PARAM_INT)));
        $row = $qb->executeQuery()->fetch();
        return $row ? (int)$row['entry_count'] : 0;
    }

    private function clean(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
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
