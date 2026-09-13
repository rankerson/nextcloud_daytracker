<?php

declare(strict_types=1);

namespace OCA\Daytracker\Controller;

use DateTimeImmutable;
use JsonException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

final class PageController extends Controller
{
    private const TABLE_CATEGORIES = 'daytracker_categories';
    private const TABLE_OPTIONS = 'daytracker_options';
    private const TABLE_ENTRIES = 'daytracker_entries';
    private const TABLE_TIMESLICES = 'daytracker_timeslices';
    private const INITIALIZED_KEY = 'catalog_initialized';
    private const INPUT_MODES = ['options', 'text', 'both'];

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IDBConnection $db,
        private readonly IUserSession $userSession,
        private readonly IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'main');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function catalog(): JSONResponse
    {
        try {
            $userId = $this->requireUserId();
            $this->ensureInitialCatalog($userId);
            return new JSONResponse($this->loadCatalog($userId));
        } catch (\Throwable $exception) {
            return $this->errorResponse('Fehler beim Laden des Katalogs.', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function saveCatalog(): JSONResponse
    {
        try {
            $userId = $this->requireUserId();
            $payload = $this->readJsonBody();
            [$timeslices, $categories] = $this->validateCatalogPayload($payload);

            $this->db->beginTransaction();
            try {
                $existingTimeslices = $this->loadOwnedTimesliceIds($userId);
                $existingCategories = $this->loadOwnedCategoryIds($userId);
                $existingOptions = $this->loadOwnedOptionMap($userId);

                $submittedTimesliceIds = $this->validateSubmittedTimeslices($timeslices, $existingTimeslices);
                [$submittedCategoryIds, $submittedOptionIds] = $this->validateSubmittedCategories(
                    $categories,
                    $existingCategories,
                    $existingOptions,
                );

                $deletedOptionIds = array_values(array_diff(array_keys($existingOptions), $submittedOptionIds));
                $deletedCategoryIds = array_values(array_diff($existingCategories, $submittedCategoryIds));
                $deletedTimesliceIds = array_values(array_diff($existingTimeslices, $submittedTimesliceIds));

                $this->deleteEntriesForOptions($userId, $deletedOptionIds);
                $this->deleteOptions($deletedOptionIds, $existingOptions);
                $this->deleteEntriesForCategories($userId, $deletedCategoryIds);
                $this->deleteOptionsForCategories($deletedCategoryIds, $userId);
                $this->deleteCategories($userId, $deletedCategoryIds);
                $this->deleteEntriesForTimeslices($userId, $deletedTimesliceIds);
                $this->deleteTimeslices($userId, $deletedTimesliceIds);

                $this->persistTimeslices($userId, $timeslices);
                $this->persistCategories($userId, $categories);
                $this->db->commit();
            } catch (\Throwable $exception) {
                $this->db->rollBack();
                throw $exception;
            }

            $this->config->setUserValue($userId, $this->appName, self::INITIALIZED_KEY, '1');
            return new JSONResponse([
                'status' => 'ok',
                'catalog' => $this->loadCatalog($userId),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Http::STATUS_NOT_FOUND);
        } catch (\Throwable $exception) {
            return $this->errorResponse('Fehler beim Speichern der Administration.', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function day(string $date): JSONResponse
    {
        try {
            $this->assertValidDate($date);
            $userId = $this->requireUserId();
            $this->ensureInitialCatalog($userId);

            $query = $this->db->getQueryBuilder();
            $query->select('e.category_id', 'e.timeslice_id', 'e.option_id', 'e.text_value', 'e.updated_at')
                ->from(self::TABLE_ENTRIES, 'e')
                ->innerJoin('e', self::TABLE_CATEGORIES, 'c', $query->expr()->eq('c.id', 'e.category_id'))
                ->innerJoin('e', self::TABLE_TIMESLICES, 't', $query->expr()->eq('t.id', 'e.timeslice_id'))
                ->where($query->expr()->eq('e.user_id', $query->createNamedParameter($userId)))
                ->andWhere($query->expr()->eq('e.entry_date', $query->createNamedParameter($date)))
                ->andWhere($query->expr()->eq('c.user_id', $query->createNamedParameter($userId)))
                ->andWhere($query->expr()->eq('t.user_id', $query->createNamedParameter($userId)))
                ->orderBy('t.sort_order', 'ASC')
                ->addOrderBy('c.sort_order', 'ASC');

            $entries = [];
            $result = $query->executeQuery();
            while ($row = $result->fetchAssociative()) {
                $entries[] = [
                    'category_id' => (int)$row['category_id'],
                    'timeslice_id' => (int)$row['timeslice_id'],
                    'option_id' => $row['option_id'] === null ? null : (int)$row['option_id'],
                    'text_value' => (string)($row['text_value'] ?? ''),
                    'updated_at' => (string)$row['updated_at'],
                ];
            }
            $result->closeCursor();

            return new JSONResponse(['date' => $date, 'entries' => $entries]);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $exception) {
            return $this->errorResponse('Fehler beim Laden.', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function saveDay(string $date): JSONResponse
    {
        try {
            $this->assertValidDate($date);
            $userId = $this->requireUserId();
            $payload = $this->readJsonBody();
            $categoryId = $this->positiveInt($payload['category_id'] ?? null, 'category_id');
            $timesliceId = $this->positiveInt($payload['timeslice_id'] ?? null, 'timeslice_id');
            $optionId = $this->nullablePositiveInt($payload['option_id'] ?? null, 'option_id');
            $textValue = $this->stringValue($payload['text_value'] ?? '', 'text_value');

            $category = $this->loadOwnedCategory($userId, $categoryId);
            $this->assertOwnedTimeslice($userId, $timesliceId);
            $inputMode = (string)$category['input_mode'];

            if ($inputMode === 'options') {
                if ($optionId === null) {
                    throw new \InvalidArgumentException('Für diese Kategorie ist eine Option erforderlich.');
                }
                $textValue = '';
            } elseif ($inputMode === 'text') {
                $optionId = null;
                $textValue = trim($textValue);
                if ($textValue === '') {
                    throw new \InvalidArgumentException('Für diese Kategorie ist ein Freitext erforderlich.');
                }
            } elseif ($inputMode === 'both') {
                $textValue = trim($textValue);
                if ($optionId === null && $textValue === '') {
                    throw new \InvalidArgumentException('Option oder Freitext muss gesetzt sein.');
                }
            } else {
                throw new \RuntimeException('Die Kategorie besitzt einen ungültigen Eingabemodus.');
            }

            if ($optionId !== null) {
                $this->assertOptionInOwnedCategory($userId, $categoryId, $optionId);
            }

            $updatedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->beginTransaction();
            try {
                $entryId = $this->findEntryId($userId, $date, $timesliceId, $categoryId);
                if ($entryId === null) {
                    $query = $this->db->getQueryBuilder();
                    $query->insert(self::TABLE_ENTRIES)
                        ->values([
                            'user_id' => $query->createNamedParameter($userId),
                            'entry_date' => $query->createNamedParameter($date),
                            'timeslice_id' => $query->createNamedParameter($timesliceId, IQueryBuilder::PARAM_INT),
                            'category_id' => $query->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT),
                            'option_id' => $optionId === null ? $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL) : $query->createNamedParameter($optionId, IQueryBuilder::PARAM_INT),
                            'text_value' => $query->createNamedParameter($textValue),
                            'updated_at' => $query->createNamedParameter($updatedAt),
                        ])
                        ->executeStatement();
                } else {
                    $query = $this->db->getQueryBuilder();
                    $query->update(self::TABLE_ENTRIES)
                        ->set('option_id', $optionId === null ? $query->createNamedParameter(null, IQueryBuilder::PARAM_NULL) : $query->createNamedParameter($optionId, IQueryBuilder::PARAM_INT))
                        ->set('text_value', $query->createNamedParameter($textValue))
                        ->set('updated_at', $query->createNamedParameter($updatedAt))
                        ->where($query->expr()->eq('id', $query->createNamedParameter($entryId, IQueryBuilder::PARAM_INT)))
                        ->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
                        ->executeStatement();
                }
                $this->db->commit();
            } catch (\Throwable $exception) {
                $this->db->rollBack();
                throw $exception;
            }

            return new JSONResponse([
                'status' => 'ok',
                'entry' => [
                    'category_id' => $categoryId,
                    'timeslice_id' => $timesliceId,
                    'option_id' => $optionId,
                    'text_value' => $textValue,
                    'updated_at' => $updatedAt,
                ],
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), Http::STATUS_NOT_FOUND);
        } catch (\Throwable $exception) {
            return $this->errorResponse('Fehler beim Speichern.', Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function exportCsv(): DataDownloadResponse
    {
        $userId = $this->requireUserId();
        $query = $this->db->getQueryBuilder();
        $query->select(
            'e.entry_date',
            't.name AS timeslice_name',
            'c.name AS category_name',
            'o.label AS option_label',
            'e.text_value',
            'e.updated_at',
        )
            ->from(self::TABLE_ENTRIES, 'e')
            ->innerJoin('e', self::TABLE_TIMESLICES, 't', $query->expr()->eq('t.id', 'e.timeslice_id'))
            ->innerJoin('e', self::TABLE_CATEGORIES, 'c', $query->expr()->eq('c.id', 'e.category_id'))
            ->leftJoin('e', self::TABLE_OPTIONS, 'o', $query->expr()->andX(
                $query->expr()->eq('o.id', 'e.option_id'),
                $query->expr()->eq('o.category_id', 'c.id'),
            ))
            ->where($query->expr()->eq('e.user_id', $query->createNamedParameter($userId)))
            ->andWhere($query->expr()->eq('t.user_id', $query->createNamedParameter($userId)))
            ->andWhere($query->expr()->eq('c.user_id', $query->createNamedParameter($userId)))
            ->orderBy('e.entry_date', 'ASC')
            ->addOrderBy('t.sort_order', 'ASC')
            ->addOrderBy('c.sort_order', 'ASC')
            ->addOrderBy('e.id', 'ASC');

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('CSV-Ausgabe konnte nicht erzeugt werden.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Datum', 'Zeitscheibe', 'Kategorie', 'Option', 'Freitext', 'letztes Änderungsdatum'], ';');
        $result = $query->executeQuery();
        while ($row = $result->fetchAssociative()) {
            fputcsv($stream, [
                $this->safeCsvCell((string)$row['entry_date']),
                $this->safeCsvCell((string)$row['timeslice_name']),
                $this->safeCsvCell((string)$row['category_name']),
                $this->safeCsvCell((string)($row['option_label'] ?? '')),
                $this->safeCsvCell((string)($row['text_value'] ?? '')),
                $this->safeCsvCell((string)$row['updated_at']),
            ], ';');
        }
        $result->closeCursor();
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        if ($content === false) {
            throw new \RuntimeException('CSV-Ausgabe konnte nicht gelesen werden.');
        }

        return new DataDownloadResponse($content, 'daytracker-export.csv', 'text/csv; charset=UTF-8');
    }

    private function requireUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('Keine angemeldete Benutzerin und kein angemeldeter Benutzer gefunden.');
        }
        return $user->getUID();
    }

    /** @return array<string, mixed> */
    private function readJsonBody(): array
    {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            throw new \InvalidArgumentException('Der JSON-Request ist leer.');
        }
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Der JSON-Request ist ungültig.', 0, $exception);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new \InvalidArgumentException('Der JSON-Request muss ein Objekt sein.');
        }
        return $payload;
    }

    private function assertValidDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Das Datum muss ein gültiges Datum im Format YYYY-MM-DD sein.');
        }
    }

    private function ensureInitialCatalog(string $userId): void
    {
        if ($this->config->getUserValue($userId, $this->appName, self::INITIALIZED_KEY, '') === '1') {
            return;
        }
        if ($this->hasAnyUserData($userId)) {
            $this->config->setUserValue($userId, $this->appName, self::INITIALIZED_KEY, '1');
            return;
        }

        $this->db->beginTransaction();
        try {
            if (!$this->hasAnyUserData($userId)) {
                $timesliceId = $this->insertTimeslice($userId, 'Ganzer Tag', 1);
                if ($timesliceId <= 0) {
                    throw new \RuntimeException('Die Standardzeitscheibe konnte nicht angelegt werden.');
                }
                $workId = $this->insertCategory($userId, 'Arbeitsort', 1, 4, true, 'options');
                foreach (['HomeOffice', 'Geschäftsstelle', 'Geschäftsreise mit RK', 'Geschäftsreise ohne RK', 'Urlaub', 'Krankheit', 'keine Arbeit'] as $index => $label) {
                    $this->insertOption($workId, $label, $index + 1);
                }
                $locationId = $this->insertCategory($userId, 'Aufenthaltsort', 2, 4, true, 'options');
                foreach (['Berlin', 'Hamburg', '50%/50%', 'sonstiges'] as $index => $label) {
                    $this->insertOption($locationId, $label, $index + 1);
                }
            }
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        $this->config->setUserValue($userId, $this->appName, self::INITIALIZED_KEY, '1');
    }

    private function hasAnyUserData(string $userId): bool
    {
        foreach ([self::TABLE_CATEGORIES, self::TABLE_TIMESLICES, self::TABLE_ENTRIES] as $table) {
            $query = $this->db->getQueryBuilder();
            $query->select('id')->from($table)
                ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
                ->setMaxResults(1);
            $value = $query->executeQuery()->fetchOne();
            if ($value !== false) {
                return true;
            }
        }
        return false;
    }

    /** @return array{categories: list<array<string, mixed>>, timeslices: list<array<string, mixed>>} */
    private function loadCatalog(string $userId): array
    {
        $categories = [];
        $categoryIndex = [];
        $query = $this->db->getQueryBuilder();
        $query->select('id', 'name', 'sort_order', 'dashboard_limit', 'dashboard_enabled', 'input_mode')
            ->from(self::TABLE_CATEGORIES)
            ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
            ->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
        $result = $query->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $categoryIndex[(int)$row['id']] = count($categories);
            $categories[] = [
                'id' => (int)$row['id'], 'name' => (string)$row['name'],
                'sort_order' => (int)$row['sort_order'], 'dashboard_limit' => (int)$row['dashboard_limit'],
                'dashboard_enabled' => (bool)$row['dashboard_enabled'],
                'input_mode' => (string)$row['input_mode'], 'options' => [],
            ];
        }
        $result->closeCursor();

        if ($categories !== []) {
            $query = $this->db->getQueryBuilder();
            $query->select('o.id', 'o.category_id', 'o.label', 'o.sort_order')
                ->from(self::TABLE_OPTIONS, 'o')
                ->innerJoin('o', self::TABLE_CATEGORIES, 'c', $query->expr()->eq('c.id', 'o.category_id'))
                ->where($query->expr()->eq('c.user_id', $query->createNamedParameter($userId)))
                ->orderBy('c.sort_order', 'ASC')->addOrderBy('o.sort_order', 'ASC')->addOrderBy('o.id', 'ASC');
            $result = $query->executeQuery();
            while ($row = $result->fetchAssociative()) {
                $categoryId = (int)$row['category_id'];
                if (isset($categoryIndex[$categoryId])) {
                    $categories[$categoryIndex[$categoryId]]['options'][] = [
                        'id' => (int)$row['id'], 'label' => (string)$row['label'], 'sort_order' => (int)$row['sort_order'],
                    ];
                }
            }
            $result->closeCursor();
        }

        $timeslices = [];
        $query = $this->db->getQueryBuilder();
        $query->select('id', 'name', 'sort_order')->from(self::TABLE_TIMESLICES)
            ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
            ->orderBy('sort_order', 'ASC')->addOrderBy('id', 'ASC');
        $result = $query->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $timeslices[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'sort_order' => (int)$row['sort_order']];
        }
        $result->closeCursor();
        return ['categories' => $categories, 'timeslices' => $timeslices];
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function validateCatalogPayload(array $payload): array
    {
        if (!isset($payload['timeslices']) || !is_array($payload['timeslices']) || !array_is_list($payload['timeslices'])) {
            throw new \InvalidArgumentException('timeslices muss ein Array sein.');
        }
        if (!isset($payload['categories']) || !is_array($payload['categories']) || !array_is_list($payload['categories'])) {
            throw new \InvalidArgumentException('categories muss ein Array sein.');
        }
        if ($payload['timeslices'] === []) {
            throw new \InvalidArgumentException('Mindestens eine Zeitscheibe muss erhalten bleiben.');
        }
        foreach ($payload['timeslices'] as $timeslice) {
            if (!is_array($timeslice)) { throw new \InvalidArgumentException('Ungültige Zeitscheibe.'); }
            $this->nullablePositiveInt($timeslice['id'] ?? null, 'timeslice.id');
            $this->nonEmptyString($timeslice['name'] ?? null, 'timeslice.name');
        }
        foreach ($payload['categories'] as $category) {
            if (!is_array($category)) { throw new \InvalidArgumentException('Ungültige Kategorie.'); }
            $this->nullablePositiveInt($category['id'] ?? null, 'category.id');
            $this->nonEmptyString($category['name'] ?? null, 'category.name');
            $mode = $this->nonEmptyString($category['input_mode'] ?? null, 'category.input_mode');
            if (!in_array($mode, self::INPUT_MODES, true)) { throw new \InvalidArgumentException('Ungültiger Eingabemodus.'); }
            $limit = $category['dashboard_limit'] ?? null;
            if (!is_int($limit) || $limit < 0) { throw new \InvalidArgumentException('dashboard_limit muss eine nicht negative Ganzzahl sein.'); }
            if (!array_key_exists('dashboard_enabled', $category) || !is_bool($category['dashboard_enabled'])) {
                throw new \InvalidArgumentException('dashboard_enabled muss ein Boolean-Wert sein.');
            }
            if (!isset($category['options']) || !is_array($category['options']) || !array_is_list($category['options'])) {
                throw new \InvalidArgumentException('category.options muss ein Array sein.');
            }
            foreach ($category['options'] as $option) {
                if (!is_array($option)) { throw new \InvalidArgumentException('Ungültige Option.'); }
                $this->nullablePositiveInt($option['id'] ?? null, 'option.id');
                $this->nonEmptyString($option['label'] ?? null, 'option.label');
            }
        }
        return [$payload['timeslices'], $payload['categories']];
    }

    /** @param list<array<string, mixed>> $timeslices @param list<int> $existing @return list<int> */
    private function validateSubmittedTimeslices(array $timeslices, array $existing): array
    {
        $submitted = [];
        foreach ($timeslices as $timeslice) {
            $id = $timeslice['id'] ?? null;
            if ($id !== null) {
                $id = (int)$id;
                if (!in_array($id, $existing, true)) { throw new \RuntimeException('Eine Zeitscheibe wurde nicht gefunden.'); }
                if (in_array($id, $submitted, true)) { throw new \InvalidArgumentException('Eine Zeitscheiben-ID kommt mehrfach vor.'); }
                $submitted[] = $id;
            }
        }
        return $submitted;
    }

    /** @param list<array<string, mixed>> $categories @param list<int> $existingCategories @param array<int, int> $existingOptions @return array{0: list<int>, 1: list<int>} */
    private function validateSubmittedCategories(array $categories, array $existingCategories, array $existingOptions): array
    {
        $submittedCategories = [];
        $submittedOptions = [];
        foreach ($categories as $category) {
            $categoryId = $category['id'] ?? null;
            if ($categoryId !== null) {
                $categoryId = (int)$categoryId;
                if (!in_array($categoryId, $existingCategories, true)) { throw new \RuntimeException('Eine Kategorie wurde nicht gefunden.'); }
                if (in_array($categoryId, $submittedCategories, true)) { throw new \InvalidArgumentException('Eine Kategorie-ID kommt mehrfach vor.'); }
                $submittedCategories[] = $categoryId;
            }
            foreach ($category['options'] as $option) {
                $optionId = $option['id'] ?? null;
                if ($optionId === null) { continue; }
                $optionId = (int)$optionId;
                if ($categoryId === null || !isset($existingOptions[$optionId]) || $existingOptions[$optionId] !== $categoryId) {
                    throw new \RuntimeException('Eine Option gehört nicht zur angegebenen Kategorie.');
                }
                if (in_array($optionId, $submittedOptions, true)) { throw new \InvalidArgumentException('Eine Options-ID kommt mehrfach vor.'); }
                $submittedOptions[] = $optionId;
            }
        }
        return [$submittedCategories, $submittedOptions];
    }

    /** @return list<int> */
    private function loadOwnedTimesliceIds(string $userId): array { return $this->loadOwnedIds(self::TABLE_TIMESLICES, $userId); }
    /** @return list<int> */
    private function loadOwnedCategoryIds(string $userId): array { return $this->loadOwnedIds(self::TABLE_CATEGORIES, $userId); }

    /** @return list<int> */
    private function loadOwnedIds(string $table, string $userId): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('id')->from($table)->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)));
        $ids = [];
        $result = $query->executeQuery();
        while ($id = $result->fetchOne()) { $ids[] = (int)$id; }
        $result->closeCursor();
        return $ids;
    }

    /** @return array<int, int> option id => category id */
    private function loadOwnedOptionMap(string $userId): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('o.id', 'o.category_id')->from(self::TABLE_OPTIONS, 'o')
            ->innerJoin('o', self::TABLE_CATEGORIES, 'c', $query->expr()->eq('c.id', 'o.category_id'))
            ->where($query->expr()->eq('c.user_id', $query->createNamedParameter($userId)));
        $map = [];
        $result = $query->executeQuery();
        while ($row = $result->fetchAssociative()) { $map[(int)$row['id']] = (int)$row['category_id']; }
        $result->closeCursor();
        return $map;
    }

    /** @param list<array<string, mixed>> $timeslices */
    private function persistTimeslices(string $userId, array $timeslices): void
    {
        foreach ($timeslices as $index => $timeslice) {
            $id = $timeslice['id'] ?? null;
            $name = trim((string)$timeslice['name']);
            if ($id === null) { $this->insertTimeslice($userId, $name, $index + 1); continue; }
            $query = $this->db->getQueryBuilder();
            $query->update(self::TABLE_TIMESLICES)->set('name', $query->createNamedParameter($name))
                ->set('sort_order', $query->createNamedParameter($index + 1, IQueryBuilder::PARAM_INT))
                ->where($query->expr()->eq('id', $query->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)))
                ->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)))->executeStatement();
        }
    }

    /** @param list<array<string, mixed>> $categories */
    private function persistCategories(string $userId, array $categories): void
    {
        foreach ($categories as $categoryIndex => $category) {
            $categoryId = $category['id'] ?? null;
            if ($categoryId === null) {
                $categoryId = $this->insertCategory($userId, trim((string)$category['name']), $categoryIndex + 1, (int)$category['dashboard_limit'], (bool)$category['dashboard_enabled'], (string)$category['input_mode']);
            } else {
                $categoryId = (int)$categoryId;
                $query = $this->db->getQueryBuilder();
                $query->update(self::TABLE_CATEGORIES)
                    ->set('name', $query->createNamedParameter(trim((string)$category['name'])))
                    ->set('sort_order', $query->createNamedParameter($categoryIndex + 1, IQueryBuilder::PARAM_INT))
                    ->set('dashboard_limit', $query->createNamedParameter((int)$category['dashboard_limit'], IQueryBuilder::PARAM_INT))
                    ->set('dashboard_enabled', $query->createNamedParameter((bool)$category['dashboard_enabled'], IQueryBuilder::PARAM_BOOL))
                    ->set('input_mode', $query->createNamedParameter((string)$category['input_mode']))
                    ->where($query->expr()->eq('id', $query->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)))->executeStatement();
            }
            foreach ($category['options'] as $optionIndex => $option) {
                $optionId = $option['id'] ?? null;
                if ($optionId === null) { $this->insertOption($categoryId, trim((string)$option['label']), $optionIndex + 1); continue; }
                $query = $this->db->getQueryBuilder();
                $query->update(self::TABLE_OPTIONS)->set('label', $query->createNamedParameter(trim((string)$option['label'])))
                    ->set('sort_order', $query->createNamedParameter($optionIndex + 1, IQueryBuilder::PARAM_INT))
                    ->where($query->expr()->eq('id', $query->createNamedParameter((int)$optionId, IQueryBuilder::PARAM_INT)))
                    ->andWhere($query->expr()->eq('category_id', $query->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))->executeStatement();
            }
        }
    }

    private function insertTimeslice(string $userId, string $name, int $sortOrder): int
    {
        $query = $this->db->getQueryBuilder();
        $query->insert(self::TABLE_TIMESLICES)->values([
            'user_id' => $query->createNamedParameter($userId), 'name' => $query->createNamedParameter($name),
            'sort_order' => $query->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        return (int)$this->db->lastInsertId(self::TABLE_TIMESLICES);
    }

    private function insertCategory(string $userId, string $name, int $sortOrder, int $dashboardLimit, bool $dashboardEnabled, string $inputMode): int
    {
        $query = $this->db->getQueryBuilder();
        $query->insert(self::TABLE_CATEGORIES)->values([
            'user_id' => $query->createNamedParameter($userId), 'name' => $query->createNamedParameter($name),
            'sort_order' => $query->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
            'dashboard_limit' => $query->createNamedParameter($dashboardLimit, IQueryBuilder::PARAM_INT),
            'dashboard_enabled' => $query->createNamedParameter($dashboardEnabled, IQueryBuilder::PARAM_BOOL),
            'input_mode' => $query->createNamedParameter($inputMode),
        ])->executeStatement();
        return (int)$this->db->lastInsertId(self::TABLE_CATEGORIES);
    }

    private function insertOption(int $categoryId, string $label, int $sortOrder): int
    {
        $query = $this->db->getQueryBuilder();
        $query->insert(self::TABLE_OPTIONS)->values([
            'category_id' => $query->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT),
            'label' => $query->createNamedParameter($label),
            'sort_order' => $query->createNamedParameter($sortOrder, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        return (int)$this->db->lastInsertId(self::TABLE_OPTIONS);
    }

    /** @return array<string, mixed> */
    private function loadOwnedCategory(string $userId, int $categoryId): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('id', 'input_mode')->from(self::TABLE_CATEGORIES)
            ->where($query->expr()->eq('id', $query->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)));
        $row = $query->executeQuery()->fetchAssociative();
        if ($row === false) { throw new \RuntimeException('Die Kategorie wurde nicht gefunden.'); }
        return $row;
    }

    private function assertOwnedTimeslice(string $userId, int $timesliceId): void
    {
        $query = $this->db->getQueryBuilder();
        $query->select('id')->from(self::TABLE_TIMESLICES)
            ->where($query->expr()->eq('id', $query->createNamedParameter($timesliceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)));
        if ($query->executeQuery()->fetchOne() === false) { throw new \RuntimeException('Die Zeitscheibe wurde nicht gefunden.'); }
    }

    private function assertOptionInOwnedCategory(string $userId, int $categoryId, int $optionId): void
    {
        $query = $this->db->getQueryBuilder();
        $query->select('o.id')->from(self::TABLE_OPTIONS, 'o')
            ->innerJoin('o', self::TABLE_CATEGORIES, 'c', $query->expr()->eq('c.id', 'o.category_id'))
            ->where($query->expr()->eq('o.id', $query->createNamedParameter($optionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('o.category_id', $query->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('c.user_id', $query->createNamedParameter($userId)));
        if ($query->executeQuery()->fetchOne() === false) { throw new \RuntimeException('Die Option wurde nicht gefunden.'); }
    }

    private function findEntryId(string $userId, string $date, int $timesliceId, int $categoryId): ?int
    {
        $query = $this->db->getQueryBuilder();
        $query->select('id')->from(self::TABLE_ENTRIES)
            ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
            ->andWhere($query->expr()->eq('entry_date', $query->createNamedParameter($date)))
            ->andWhere($query->expr()->eq('timeslice_id', $query->createNamedParameter($timesliceId, IQueryBuilder::PARAM_INT)))
            ->andWhere($query->expr()->eq('category_id', $query->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
        $id = $query->executeQuery()->fetchOne();
        return $id === false ? null : (int)$id;
    }

    /** @param list<int> $ids */
    private function deleteEntriesForOptions(string $userId, array $ids): void { $this->deleteEntriesByIds($userId, 'option_id', $ids); }
    /** @param list<int> $ids */
    private function deleteEntriesForCategories(string $userId, array $ids): void { $this->deleteEntriesByIds($userId, 'category_id', $ids); }
    /** @param list<int> $ids */
    private function deleteEntriesForTimeslices(string $userId, array $ids): void { $this->deleteEntriesByIds($userId, 'timeslice_id', $ids); }

    /** @param list<int> $ids */
    private function deleteEntriesByIds(string $userId, string $column, array $ids): void
    {
        if ($ids === []) { return; }
        $query = $this->db->getQueryBuilder();
        $query->delete(self::TABLE_ENTRIES)
            ->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
            ->andWhere($query->expr()->in($column, $query->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeStatement();
    }

    /** @param list<int> $ids @param array<int, int> $ownedOptions */
    private function deleteOptions(array $ids, array $ownedOptions): void
    {
        if ($ids === []) { return; }
        $safeIds = array_values(array_intersect($ids, array_keys($ownedOptions)));
        if ($safeIds === []) { return; }
        $query = $this->db->getQueryBuilder();
        $query->delete(self::TABLE_OPTIONS)
            ->where($query->expr()->in('id', $query->createNamedParameter($safeIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeStatement();
    }

    /** @param list<int> $categoryIds */
    private function deleteOptionsForCategories(array $categoryIds, string $userId): void
    {
        if ($categoryIds === []) { return; }
        $owned = $this->loadOwnedCategoryIds($userId);
        $safeIds = array_values(array_intersect($categoryIds, $owned));
        if ($safeIds === []) { return; }
        $query = $this->db->getQueryBuilder();
        $query->delete(self::TABLE_OPTIONS)
            ->where($query->expr()->in('category_id', $query->createNamedParameter($safeIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeStatement();
    }

    /** @param list<int> $ids */
    private function deleteCategories(string $userId, array $ids): void { $this->deleteOwnedRows(self::TABLE_CATEGORIES, $userId, $ids); }
    /** @param list<int> $ids */
    private function deleteTimeslices(string $userId, array $ids): void { $this->deleteOwnedRows(self::TABLE_TIMESLICES, $userId, $ids); }

    /** @param list<int> $ids */
    private function deleteOwnedRows(string $table, string $userId, array $ids): void
    {
        if ($ids === []) { return; }
        $query = $this->db->getQueryBuilder();
        $query->delete($table)->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)))
            ->andWhere($query->expr()->in('id', $query->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->executeStatement();
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) { throw new \InvalidArgumentException($field . ' muss eine positive Ganzzahl sein.'); }
        return $value;
    }

    private function nullablePositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null) { return null; }
        return $this->positiveInt($value, $field);
    }

    private function stringValue(mixed $value, string $field): string
    {
        if (!is_string($value)) { throw new \InvalidArgumentException($field . ' muss eine Zeichenkette sein.'); }
        return $value;
    }

    private function nonEmptyString(mixed $value, string $field): string
    {
        $text = trim($this->stringValue($value, $field));
        if ($text === '') { throw new \InvalidArgumentException($field . ' darf nicht leer sein.'); }
        return $text;
    }

    private function safeCsvCell(string $value): string
    {
        return preg_match('/^[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    }

    private function errorResponse(string $message, int $status): JSONResponse
    {
        return new JSONResponse(['status' => 'error', 'message' => $message], $status);
    }
}
