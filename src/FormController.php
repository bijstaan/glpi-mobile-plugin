<?php

namespace GlpiPlugin\Glpimobile;

use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Form\AccessControl\FormAccessControlManager;
use Glpi\Form\AccessControl\FormAccessParameters;
use Glpi\Form\AnswersHandler\AnswersHandler;
use Glpi\Form\Category;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\Section;
use Glpi\Form\ServiceCatalog\ItemRequest;
use Glpi\Form\ServiceCatalog\Provider\CategoryProvider;
use Glpi\Form\ServiceCatalog\ServiceCatalogCompositeInterface;
use Glpi\Form\ServiceCatalog\ServiceCatalogItemInterface;
use Glpi\Form\ServiceCatalog\ServiceCatalogManager;
use Glpi\Form\ServiceCatalog\SortStrategy\SortStrategyEnum;
use Glpi\Http\JSONResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;
use Entity;
use KnowbaseItem;
use Session;
use Throwable;

/**
 * The Service Catalog for the mobile app: list the forms a technician may
 * answer, serialize a form's sections/questions (with dropdown options resolved
 * server-side so the app needs no GLPI itemtype knowledge), and submit answers
 * through GLPI's own AnswersHandler — so the form's destination config maps
 * answers to ticket fields exactly as the web UI does.
 *
 * GLPI 11 has no REST/OAuth API for forms (only session-authenticated Symfony
 * web controllers), which is why this lives in the plugin.
 */
#[Route(path: '/GlpiMobile', tags: ['GlpiMobile'])]
final class FormController extends AbstractController
{
    protected static function getRawKnownSchemas(string $api_version = ''): array
    {
        return [];
    }

    /** The service catalog: forms this user can answer. */
    #[Route(path: '/forms', methods: ['GET'])]
    #[RouteVersion(introduced: '2.0')]
    public function listForms(Request $request): Response
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }

        $items = [];
        try {
            $catalog = ServiceCatalogManager::getInstance();
            $result  = $catalog->getItems(new ItemRequest(
                access_parameters: self::accessParameters(),
                filter: trim((string) $request->getParameter('filter')),
                items_per_page: 100,
            ));
            // The catalog yields mixed provider items; keep the Forms.
            foreach (($result['items'] ?? $result) as $item) {
                if (!$item instanceof Form) {
                    continue;
                }
                $items[] = self::formSummary($item);
            }
        } catch (Throwable) {
            $items = [];
        }

        // Fall back to a direct query when the catalog yields nothing (e.g. a
        // provider that only serves the end-user helpdesk view).
        if ($items === []) {
            foreach (self::activeForms() as $form) {
                $items[] = self::formSummary($form);
            }
        }
        return new JSONResponse($items, 200);
    }

    /**
     * The service catalog as the web UI arranges it: one level of the category
     * tree, with the entity's own display settings.
     *
     * The flat `/forms` list above is still what an older app asks for, and it
     * is still the right answer for "show me everything I could file". It is
     * the wrong answer for an instance that has organised its intake: GLPI 11
     * puts forms in a category tree, and a catalog with forty forms in it is
     * unusable as one list on a phone — which is exactly why the web UI stopped
     * being one.
     *
     * Everything that decides *shape* is read from the same places the web
     * controller reads it, so the two cannot drift:
     *
     *  - `category` (0 = the root level, as `ServiceCatalog\ItemsController`
     *    defaults it) walks the tree; `ancestors` is the breadcrumb;
     *  - a non-empty `filter` searches **across** categories, which is why the
     *    category is dropped when one is given — the same rule, for the same
     *    reason: somebody searching wants the form, not the folder;
     *  - `expand_categories` is the entity's *Expand categories in the service
     *    catalog* setting, inherited from the parent entity like every other
     *    entity option. It is the difference between a category you tap into
     *    and a category rendered as a section with its forms already under it;
     *  - the sort strategy is the entity's default unless the caller names one.
     *
     * Empty categories never appear: `ServiceCatalogManager` drops them at both
     * levels, and a folder that opens onto nothing is worse on a phone than in
     * a browser, where at least the back button is free.
     */
    #[Route(path: '/catalog', methods: ['GET'])]
    #[RouteVersion(introduced: '2.0')]
    public function catalog(Request $request): Response
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }

        $filter = trim((string) self::param($request, 'filter', ''));
        $page   = max(1, (int) self::param($request, 'page', 1));
        // A phone scrolls; it does not page. The web's twelve-per-page exists
        // to fill a grid, and asking somebody to tap "next" through a catalog
        // is the friction the category tree was supposed to remove.
        $per_page = max(1, min(200, (int) self::param($request, 'per_page', 100)));

        $category_id = (int) self::param($request, 'category', 0);
        if ($category_id > 0 && Category::getById($category_id) === false) {
            return new JSONResponse(['error' => 'category_not_found'], 404);
        }

        $session = Session::getCurrentSessionInfo();
        $entity  = Entity::getById($session?->getCurrentEntityId() ?? 0);

        $sort = SortStrategyEnum::tryFrom((string) self::param($request, 'sort', ''));
        if ($sort === null) {
            $sort = $entity instanceof Entity
                ? $entity->getServiceCatalogDefaultSortStrategy()
                : SortStrategyEnum::POPULARITY;
        }

        $item_request = new ItemRequest(
            access_parameters: self::accessParameters(),
            filter: $filter,
            // Null rather than 0: searching spans the whole tree.
            category_id: $filter !== '' ? null : $category_id,
            page: $page,
            items_per_page: $per_page,
            sort_strategy: $sort,
        );

        try {
            $result = ServiceCatalogManager::getInstance()->getItems($item_request);
        } catch (Throwable) {
            // The catalog is the plugin's only view of intake; if it cannot be
            // built, an empty level is a better answer than a 500, and the app
            // falls back to the flat list.
            return new JSONResponse([
                'expand_categories' => false,
                'sort_strategy'     => $sort->value,
                'category_id'       => $category_id,
                'ancestors'         => [],
                'items'             => [],
                'total'             => 0,
                'page'              => $page,
                'per_page'          => $per_page,
            ], 200);
        }

        $items = [];
        foreach (($result['items'] ?? []) as $item) {
            $row = self::catalogItem($item);
            if ($row !== null) {
                $items[] = $row;
            }
        }

        $ancestors = [];
        if ($category_id > 0 && $filter === '') {
            foreach ((new CategoryProvider())->getAncestors($item_request) as $ancestor) {
                $ancestors[] = [
                    'id'   => (int) ($ancestor['id'] ?? 0),
                    'name' => (string) ($ancestor['name'] ?? ''),
                ];
            }
        }

        return new JSONResponse([
            // The entity setting, resolved through the inheritance chain by
            // Entity itself rather than read off the column — the stored value
            // is usually "inherit".
            'expand_categories' => $entity instanceof Entity
                && $entity->shouldExpandCategoriesInServiceCatalog(),
            'sort_strategy'     => $sort->value,
            'category_id'       => $category_id,
            'ancestors'         => $ancestors,
            'items'             => $items,
            'total'             => (int) ($result['total'] ?? count($items)),
            'page'              => $page,
            'per_page'          => $per_page,
        ], 200);
    }

    /**
     * The catalog's own artwork: `?ids=report-issue,request-service`.
     *
     * GLPI draws these from a 1.8 MB SVG sprite that the web page references
     * with `<use href="…#id">`, which is no use to an app: it cannot resolve a
     * fragment of a file it has not got, and it is not going to download the
     * sprite to draw six icons. So each requested symbol is lifted out and
     * returned as a standalone SVG.
     *
     * Two substitutions make them renderable outside a browser:
     *
     *  - the fills are CSS custom properties with literal fallbacks
     *    (`var(--glpi-illustrations-color, var(--…-header-dark, #2F3F64))`).
     *    Nothing outside a stylesheet resolves those, so the innermost literal
     *    is written in — which is exactly what a browser paints on the default
     *    palette;
     *  - the symbol's own `viewBox` becomes the SVG's, or it would scale to
     *    nothing.
     *
     * Batched on purpose: a catalog screen wants every icon it is about to
     * draw, and one request for ten is the difference between a list that
     * paints and a list that flickers in.
     */
    #[Route(path: '/illustrations', methods: ['GET'])]
    #[RouteVersion(introduced: '2.0')]
    public function illustrations(Request $request): Response
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }

        $raw = (string) self::param($request, 'ids', '');
        $ids = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $id): bool => $id !== ''
                && preg_match('/^(custom:)?[A-Za-z0-9_.\- ]{1,190}$/', $id) === 1
        )));

        // A screenful, not a scrape of the whole sprite.
        $ids = array_slice($ids, 0, 40);

        $out = [];
        foreach ($ids as $id) {
            $svg = str_starts_with($id, 'custom:')
                ? self::customIllustration(substr($id, 7))
                : self::spriteIllustration($id);

            if ($svg !== null) {
                $out[$id] = $svg;
            }
        }

        // Static artwork keyed by name: worth a day in a cache, and the app
        // keeps them for the session anyway.
        return new Response(
            200,
            [
                'Content-Type'  => 'application/json',
                'Cache-Control' => 'public, max-age=86400',
            ],
            json_encode((object) $out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
    }

    /** A native illustration, lifted out of GLPI's sprite. */
    private static function spriteIllustration(string $id): ?string
    {
        $sprite = GLPI_ROOT
            . '/public/lib/glpi-project/illustrations/glpi-illustrations-icons.svg';

        if (!is_readable($sprite)) {
            return null;
        }

        // Read once per request: a catalog screen asks for several icons, and
        // re-reading 1.8 MB per icon would be the cost of this endpoint.
        static $contents = null;
        $contents ??= (string) file_get_contents($sprite);

        // The id is already charset-checked; quoting it keeps a name with a dot
        // in it from being a pattern.
        $pattern = '/<symbol\b([^>]*\bid="' . preg_quote($id, '/') . '"[^>]*)>(.*?)<\/symbol>/s';
        if (preg_match($pattern, $contents, $match) !== 1) {
            return null;
        }

        $view_box = preg_match('/viewBox="([^"]+)"/', $match[1], $vb) === 1
            ? $vb[1]
            : '0 0 128 128';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $view_box . '"'
            . ' fill="none">' . self::resolveCssVars($match[2]) . '</svg>';
    }

    /**
     * An illustration an administrator uploaded.
     *
     * SVG only. A raster custom illustration is a perfectly good file and the
     * wrong thing to put in a JSON map of markup; the app falls back to its own
     * icon, which is what it does for anything it cannot draw.
     */
    private static function customIllustration(string $file): ?string
    {
        // Basename only: the id arrives from a client, and this reads a path.
        $file = basename($file);
        if ($file === '' || !str_ends_with(strtolower($file), '.svg')) {
            return null;
        }

        $path = GLPI_PICTURE_DIR . '/illustrations/' . $file;
        if (!is_readable($path)) {
            return null;
        }

        return self::resolveCssVars((string) file_get_contents($path));
    }

    /**
     * `var(--a, var(--b, #hex))` → `#hex`.
     *
     * The innermost fallback is what a browser paints when no palette
     * overrides the property, which is the default GLPI look — and the one the
     * app's own light theme was built against.
     */
    private static function resolveCssVars(string $svg): string
    {
        $previous = null;
        // Nested twice in the sprite today; loop rather than assume the depth.
        while ($previous !== $svg) {
            $previous = $svg;
            $svg = (string) preg_replace(
                '/var\(\s*--[A-Za-z0-9-]+\s*,\s*([^(),]+?)\s*\)/',
                '$1',
                $svg
            );
        }

        return $svg;
    }

    /** A form's full definition: sections, questions, options. */
    #[Route(path: '/forms/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function getForm(Request $request): Response
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        $form = Form::getById((int) $request->getAttribute('id'));
        if (!$form instanceof Form || !self::canAnswer($form)) {
            return new JSONResponse(['error' => 'form_not_found'], 404);
        }

        $sections = [];
        foreach ($form->getSections() as $section) {
            $sections[] = [
                'id'          => $section->getID(),
                'uuid'        => $section->getUUID(),
                'name'        => $section->fields['name'] ?? '',
                'description' => $section->fields['description'] ?? '',
                'rank'        => (int) ($section->fields['rank'] ?? 0),
                'visibility_strategy' => (string) ($section->fields['visibility_strategy'] ?? ''),
                'conditions'  => self::decode($section->fields['conditions'] ?? '[]'),
                'questions'   => array_map(
                    static fn(Question $q) => self::question($q),
                    array_values($section->getQuestions())
                ),
            ];
        }

        return new JSONResponse(
            self::formSummary($form) + ['sections' => $sections],
            200
        );
    }

    /**
     * Submit answers: `{"answers": {"<question_id>": value, ...}}`.
     * Returns the created items (typically the new ticket).
     */
    #[Route(path: '/forms/{id}/submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function submit(Request $request): Response
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        $form = Form::getById((int) $request->getAttribute('id'));
        if (!$form instanceof Form || !self::canAnswer($form)) {
            return new JSONResponse(['error' => 'form_not_found'], 404);
        }

        /** @var \DBmysql $DB */
        global $DB;

        // Idempotency: a retried offline submit must not file a second ticket.
        $marker = trim((string) $request->getParameter('marker'));
        if ($marker !== '') {
            foreach (
                $DB->request([
                    'FROM'  => 'glpi_plugin_glpimobile_formsubmits',
                    'WHERE' => ['marker' => $marker],
                    'LIMIT' => 1,
                ]) as $prior
            ) {
                return new JSONResponse([
                    'answers_set_id' => (int) $prior['answers_set_id'],
                    'created' => json_decode((string) $prior['created_json'], true) ?: [],
                ], 200);
            }
        }

        $raw = $request->getParameter('answers');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return new JSONResponse(['error' => 'invalid_answers'], 400);
        }
        // Keys arrive as strings over JSON; AnswersHandler keys by question id.
        // Values must also carry the right PHP type: GLPI's destination field
        // strategies index into them (e.g. ITILCategoryFieldStrategy does
        // $answer[...]), so a numeric answer sent as "7" instead of 7 fatals
        // with "Cannot access offset of type string on string".
        $types = [];
        foreach ($form->getQuestions() as $question) {
            $types[$question->getID()] = self::typeSlug((string) ($question->fields['type'] ?? ''));
        }
        $answers = [];
        foreach ($raw as $qid => $value) {
            $id = (int) $qid;
            $answers[$id] = self::coerce($types[$id] ?? '', $value);
        }

        $handler = AnswersHandler::getInstance();
        try {
            if (!$handler->validateAnswers($form, $answers)->isValid()) {
                return new JSONResponse(['error' => 'validation_failed'], 422);
            }
            $answers = $handler->removeUnusedAnswers($form, $answers);
            $set     = $handler->saveAnswers($form, $answers, $uid, []);
        } catch (Throwable $e) {
            return new JSONResponse(
                ['error' => 'submit_failed', 'detail' => $e->getMessage()],
                500
            );
        }

        // Resolve what the destinations created (the ticket the app should open).
        $created = [];
        foreach (self::createdItems((int) $set->getID()) as $row) {
            $created[] = ['itemtype' => $row['itemtype'], 'id' => (int) $row['items_id']];
        }

        if ($marker !== '') {
            $DB->insert('glpi_plugin_glpimobile_formsubmits', [
                'marker'         => $marker,
                'users_id'       => $uid,
                'forms_id'       => $form->getID(),
                'answers_set_id' => (int) $set->getID(),
                'created_json'   => json_encode($created),
                'date_creation'  => date('Y-m-d H:i:s'),
            ]);
        }

        return new JSONResponse(
            ['answers_set_id' => (int) $set->getID(), 'created' => $created],
            201
        );
    }

    // --- Helpers ---

    /** Optional-parameter read: core's getParameter() warns on absent keys. */
    private static function param(Request $request, string $name, mixed $default = null): mixed
    {
        return $request->hasParameter($name) ? $request->getParameter($name) : $default;
    }

    /**
     * One catalog entry in the app's vocabulary.
     *
     * `kind` is what the app switches on, and it is the item's *role* in the
     * catalog rather than its class: a form is filed, a category is descended
     * into (or expanded in place), and a knowledge article is read. The web
     * catalog lists all three in the same grid; leaving one out here would make
     * the app's list quietly different from the one people are used to.
     *
     * Categories carry the single level of children the manager has already
     * loaded — that is what the *expand* setting renders as a section, and
     * re-fetching it per category would be a request per folder.
     *
     * @return array<string,mixed>|null null for a provider this app does not
     *                                  know how to open, which is better
     *                                  dropped than shown as a dead row
     */
    private static function catalogItem(ServiceCatalogItemInterface $item): ?array
    {
        $kind = match (true) {
            $item instanceof Form          => 'form',
            $item instanceof Category      => 'category',
            $item instanceof KnowbaseItem  => 'kb',
            default                        => null,
        };

        if ($kind === null) {
            return null;
        }

        $row = [
            'kind'         => $kind,
            'id'           => (int) $item->getID(),
            'name'         => $item->getServiceCatalogItemTitle(),
            'description'  => self::plain($item->getServiceCatalogItemDescription()),
            'illustration' => $item->getServiceCatalogItemIllustration(),
            'pinned'       => $item->isServiceCatalogItemPinned(),
        ];

        if ($item instanceof ServiceCatalogCompositeInterface) {
            $children = [];
            foreach ($item->getChildren() as $child) {
                $child_row = self::catalogItem($child);
                if ($child_row !== null) {
                    $children[] = $child_row;
                }
            }
            $row['children'] = $children;
        }

        return $row;
    }

    /**
     * Descriptions are TinyMCE HTML in GLPI and plain text in the app.
     *
     * Entities are decoded rather than left as they are: `&amp;` in a list row
     * is the kind of thing that looks like a corrupt record to whoever reads
     * it, and the app has no HTML renderer on this screen.
     */
    private static function plain(string $html): string
    {
        $text = html_entity_decode(
            strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', $html)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }


    /** Question types whose answer GLPI expects as an integer id/enum. */
    private const INT_TYPES = [
        'urgency', 'request_type', 'number', 'item_dropdown', 'item', 'user_device',
    ];

    /** Coerce a JSON-decoded answer to the PHP type GLPI's handlers expect. */
    private static function coerce(string $slug, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (in_array($slug, self::INT_TYPES, true)) {
            if (is_array($value)) {
                return array_map(static fn($v) => (int) $v, $value);
            }
            return is_numeric($value) ? (int) $value : $value;
        }
        // Actor questions take a list of "users_id-<id>" strings.
        if (in_array($slug, ['requester', 'observer', 'assignee'], true)) {
            return is_array($value) ? array_values($value) : [$value];
        }
        // Multi-select choices stay arrays; text stays text.
        return $value;
    }

    /** @return iterable<Form> active, non-deleted, non-draft forms in scope. */
    private static function activeForms(): iterable
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_forms_forms',
                'WHERE'  => [
                    'is_active'  => 1,
                    'is_deleted' => 0,
                    'is_draft'   => 0,
                ] + getEntitiesRestrictCriteria('glpi_forms_forms', '', '', true),
                'ORDER'  => 'name ASC',
            ]) as $row
        ) {
            $form = Form::getById((int) $row['id']);
            if ($form instanceof Form && self::canAnswer($form)) {
                yield $form;
            }
        }
    }

    private static function accessParameters(): FormAccessParameters
    {
        return new FormAccessParameters(
            // `Session::getCurrentSessionInfo()`, not `SessionInfo::` — the
            // latter is not a thing, and because the catalog call above is
            // wrapped in a catch-all, calling it silently sent every request
            // down the fallback path instead: the flat list worked, and the
            // service catalog it was supposed to be reading was never
            // consulted at all.
            session_info: Session::getCurrentSessionInfo(),
            url_parameters: [],
        );
    }

    private static function canAnswer(Form $form): bool
    {
        try {
            return FormAccessControlManager::getInstance()
                ->canAnswerForm($form, self::accessParameters());
        } catch (Throwable) {
            // No access controls configured → fall back to entity visibility.
            return true;
        }
    }

    private static function formSummary(Form $form): array
    {
        return [
            'id'           => $form->getID(),
            'name'         => $form->fields['name'] ?? '',
            'description'  => strip_tags((string) ($form->fields['description'] ?? '')),
            'illustration' => (string) ($form->fields['illustration'] ?? ''),
            'category_id'  => (int) ($form->fields['forms_categories_id'] ?? 0),
            'entity_id'    => (int) ($form->fields['entities_id'] ?? 0),
        ];
    }

    private static function question(Question $q): array
    {
        $typeClass = (string) ($q->fields['type'] ?? '');
        $extra     = self::decode($q->fields['extra_data'] ?? 'null');

        return [
            'id'           => $q->getID(),
            'uuid'         => $q->getUUID(),
            'name'         => $q->fields['name'] ?? '',
            'type'         => self::typeSlug($typeClass),
            'type_class'   => $typeClass,
            'mandatory'    => (bool) ($q->fields['is_mandatory'] ?? false),
            'description'  => strip_tags((string) ($q->fields['description'] ?? '')),
            'default_value' => $q->fields['default_value'] ?? null,
            'extra_data'   => $extra,
            'rank'         => (int) ($q->fields['vertical_rank'] ?? 0),
            'visibility_strategy' => (string) ($q->fields['visibility_strategy'] ?? ''),
            'conditions'   => self::decode($q->fields['conditions'] ?? '[]'),
            // Options resolved server-side so the app renders a plain picker.
            'options'      => self::options($typeClass, $extra),
        ];
    }

    /** `Glpi\Form\QuestionType\QuestionTypeShortText` → `short_text`. */
    private static function typeSlug(string $class): string
    {
        $short = substr((string) strrchr($class, '\\'), 1) ?: $class;
        $short = preg_replace('/^QuestionType/', '', $short) ?? $short;
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short) ?? $short);
    }

    /**
     * Selectable options for a question, as `[{value,label}]`. Covers the
     * choice types (radio/checkbox/dropdown), GLPI dropdown-backed item lists
     * (category/location/…), and the fixed ITIL enums.
     */
    private static function options(string $typeClass, mixed $extra): array
    {
        $slug = self::typeSlug($typeClass);

        // Fixed ITIL enumerations.
        if ($slug === 'urgency') {
            return self::enumOptions([5 => 'Very high', 4 => 'High', 3 => 'Medium', 2 => 'Low', 1 => 'Very low']);
        }
        if ($slug === 'request_type') {
            return self::enumOptions([1 => 'Incident', 2 => 'Request']);
        }

        // Author-defined choices live in extra_data.options.
        if (in_array($slug, ['radio', 'checkbox', 'dropdown'], true)) {
            $opts = is_array($extra) ? ($extra['options'] ?? []) : [];
            $out  = [];
            foreach ($opts as $key => $label) {
                // Options can be a list of strings or a {uuid: label} map.
                if (is_array($label)) {
                    $out[] = [
                        'value' => (string) ($label['uuid'] ?? $key),
                        'label' => (string) ($label['value'] ?? ''),
                    ];
                } else {
                    $out[] = ['value' => (string) $key, 'label' => (string) $label];
                }
            }
            return $out;
        }

        // GLPI dropdown-backed lists (ITILCategory, Location, …) and plain
        // itemtype lists (Computer, Monitor, …).
        if (in_array($slug, ['item_dropdown', 'item'], true)
            && is_array($extra) && !empty($extra['itemtype'])) {
            return self::dropdownOptions((string) $extra['itemtype']);
        }

        // The requester's own assets, exactly as the web form offers them.
        if ($slug === 'user_device') {
            return self::myDeviceOptions();
        }

        return [];
    }

    private static function enumOptions(array $map): array
    {
        $out = [];
        foreach ($map as $value => $label) {
            $out[] = ['value' => (string) $value, 'label' => $label];
        }
        return $out;
    }

    /**
     * The current user's devices, keyed the way GLPI's own widget does
     * (`Itemtype_id`), so answers submit unchanged.
     */
    private static function myDeviceOptions(): array
    {
        try {
            $devices = \CommonItilObject_Item::getMyDevices(
                (int) Session::getLoginUserID(),
                Session::getActiveEntities()
            );
        } catch (Throwable) {
            return [];
        }

        // getMyDevices returns groups: [ 'Computers' => [ 'Computer_3' => 'PC-1' ] ].
        $out = [];
        foreach ($devices as $group => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $value => $label) {
                $out[] = [
                    'value' => (string) $value,
                    'label' => is_string($group) && $group !== ''
                        ? sprintf('%s — %s', $group, (string) $label)
                        : (string) $label,
                ];
            }
        }
        return $out;
    }

    /** Read a GLPI dropdown table into {value,label} pairs (tree-aware). */
    private static function dropdownOptions(string $itemtype): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!class_exists($itemtype) || !is_subclass_of($itemtype, \CommonDBTM::class)) {
            return [];
        }
        $table = $itemtype::getTable();
        $tree  = is_subclass_of($itemtype, \CommonTreeDropdown::class);
        $cols  = ['id', 'name'] + ($tree ? [2 => 'completename'] : []);

        $out = [];
        try {
            foreach (
                $DB->request([
                    'SELECT' => $cols,
                    'FROM'   => $table,
                    'WHERE'  => getEntitiesRestrictCriteria($table, '', '', true),
                    'ORDER'  => ($tree ? 'completename' : 'name') . ' ASC',
                    'LIMIT'  => 500,
                ]) as $row
            ) {
                $label = $tree
                    ? (string) ($row['completename'] ?: $row['name'])
                    : (string) $row['name'];
                if ($label === '') {
                    continue;
                }
                $out[] = ['value' => (string) $row['id'], 'label' => $label];
            }
        } catch (Throwable) {
            return [];
        }
        return $out;
    }

    private static function createdItems(int $answersSetId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => 'glpi_forms_destinations_answerssets_formdestinationitems',
                'WHERE' => ['forms_answerssets_id' => $answersSetId],
            ]) as $row
        ) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function decode(?string $json): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }
        return json_decode($json, true);
    }
}
