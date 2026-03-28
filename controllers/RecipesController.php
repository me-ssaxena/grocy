<?php

namespace Grocy\Controllers;

use Grocy\Services\RecipesService;
use Grocy\Helpers\Grocycode;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RecipesController extends BaseController
{
	use GrocycodeTrait;

	private const RECIPES_PAGE_SIZE_DEFAULT = 50;
	private const RECIPES_PAGE_SIZE_MAX = 200;

	public function MealPlan(Request $request, Response $response, array $args)
	{
		$start = date('Y-m-d');
		if (isset($request->getQueryParams()['start']) && IsIsoDate($request->getQueryParams()['start']))
		{
			$start = $request->getQueryParams()['start'];
		}

		$days = 6;
		if (isset($request->getQueryParams()['days']) && filter_var($request->getQueryParams()['days'], FILTER_VALIDATE_INT) !== false)
		{
			$days = $request->getQueryParams()['days'];
		}

		$mealPlanWhereTimespan = "day BETWEEN DATE('$start', '-$days days') AND DATE('$start', '+$days days')";

		$recipes = $this->getDatabase()->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->fetchAll();
		$events = [];
		foreach ($this->getDatabase()->meal_plan()->where($mealPlanWhereTimespan) as $mealPlanEntry)
		{
			$recipe = FindObjectInArrayByPropertyValue($recipes, 'id', $mealPlanEntry['recipe_id']);
			$title = '';

			if ($recipe !== null)
			{
				$title = $recipe->name;
			}

			$productDetails = null;
			if ($mealPlanEntry['product_id'] !== null)
			{
				$productDetails = $this->getStockService()->GetProductDetails($mealPlanEntry['product_id']);
			}

			$events[] = [
				'id' => $mealPlanEntry['id'],
				'title' => $title,
				'start' => $mealPlanEntry['day'],
				'date_format' => 'date',
				'recipe' => json_encode($recipe),
				'mealPlanEntry' => json_encode($mealPlanEntry),
				'type' => $mealPlanEntry['type'],
				'productDetails' => json_encode($productDetails)
			];
		}

		$weekRecipe = $this->getDatabase()->recipes()->where("type = 'mealplan-week' AND name = LTRIM(STRFTIME('%Y-%W', DATE('$start')), '0')")->fetch();
		$weekRecipeId = 0;
		if ($weekRecipe != null)
		{
			$weekRecipeId = $weekRecipe->id;
		}

		return $this->renderPage($response, 'mealplan', [
			'fullcalendarEventSources' => $events,
			'recipes' => $recipes,
			'internalRecipes' => $this->getDatabase()->recipes()->where("id IN (SELECT recipe_id FROM meal_plan_internal_recipe_relation WHERE $mealPlanWhereTimespan) OR id = $weekRecipeId")->fetchAll(),
			'recipesResolved' => $this->getRecipesService()->GetRecipesResolved("recipe_id IN (SELECT recipe_id FROM meal_plan_internal_recipe_relation WHERE $mealPlanWhereTimespan) OR recipe_id = $weekRecipeId"),
			'products' => $this->getDatabase()->products()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved(),
			'mealplanSections' => $this->getDatabase()->meal_plan_sections()->orderBy('sort_number'),
			'usedMealplanSections' => $this->getDatabase()->meal_plan_sections()->where("id IN (SELECT section_id FROM meal_plan WHERE $mealPlanWhereTimespan)")->orderBy('sort_number'),
			'weekRecipe' => $weekRecipe
		]);
	}

	public function Overview(Request $request, Response $response, array $args)
	{
		$queryParams = $request->getQueryParams();
		$search = trim($queryParams['search'] ?? '');
		$status = trim($queryParams['status'] ?? '');

		$page = intval($queryParams['page'] ?? 1);
		if ($page < 1)
		{
			$page = 1;
		}

		$pageSize = intval($queryParams['page_size'] ?? self::RECIPES_PAGE_SIZE_DEFAULT);
		if ($pageSize < 1)
		{
			$pageSize = self::RECIPES_PAGE_SIZE_DEFAULT;
		}
		if ($pageSize > self::RECIPES_PAGE_SIZE_MAX)
		{
			$pageSize = self::RECIPES_PAGE_SIZE_MAX;
		}

		$recipesCountQuery = $this->ApplyOverviewSearchAndStatusFilters(
			$this->getDatabase()->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL),
			$search,
			$status
		);
		$totalRecipes = intval($recipesCountQuery->count('*'));
		$totalPages = max(intval(ceil($totalRecipes / $pageSize)), 1);
		if ($page > $totalPages)
		{
			$page = $totalPages;
		}

		$offset = ($page - 1) * $pageSize;
		$recipesQuery = $this->ApplyOverviewSearchAndStatusFilters(
			$this->getDatabase()->recipes()
				->where('type', RecipesService::RECIPE_TYPE_NORMAL)
				->orderBy('name', 'COLLATE NOCASE'),
			$search,
			$status
		);
		$recipes = $recipesQuery->limit($pageSize, $offset)->fetchAll();

		$recipeIds = [];
		foreach ($recipes as $recipe)
		{
			$recipeIds[] = intval($recipe->id);
		}

		$recipesResolved = [];
		if (count($recipeIds) > 0)
		{
			$recipesResolved = $this->getRecipesService()->GetRecipesResolved('recipe_id IN (' . implode(',', $recipeIds) . ')')->fetchAll();
		}

		$viewData = [
			'recipes' => $recipes,
			'recipesForPicker' => $this->getDatabase()->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE')->fetchAll(),
			'recipesResolved' => $recipesResolved,
			'userfields' => $this->getUserfieldsService()->GetFields('recipes'),
			'userfieldValues' => $this->getUserfieldsService()->GetAllValues('recipes'),
			'mealplanSections' => $this->getDatabase()->meal_plan_sections()->orderBy('sort_number'),
			'pagination' => [
				'page' => $page,
				'pageSize' => $pageSize,
				'totalRecipes' => $totalRecipes,
				'totalPages' => $totalPages,
				'hasPreviousPage' => $page > 1,
				'hasNextPage' => $page < $totalPages,
				'previousPage' => max($page - 1, 1),
				'nextPage' => min($page + 1, $totalPages)
			],
			'selectedRecipeId' => isset($queryParams['recipe']) ? intval($queryParams['recipe']) : null,
			'queryParams' => $queryParams
		];

		return $this->renderPage($response, 'recipes', $viewData);
	}

	public function RecipeDetails(Request $request, Response $response, array $args)
	{
		$recipeId = intval($args['recipeId']);
		$dbChangedTime = $this->getDatabaseService()->GetDbChangedTime();
		$currentUserId = defined('GROCY_USER_ID') ? GROCY_USER_ID : 0;
		$etag = '"' . md5('recipe_details_' . $recipeId . '_' . $currentUserId . '_' . $dbChangedTime) . '"';
		$lastModifiedTimestamp = strtotime($dbChangedTime);

		$ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));
		if ($ifNoneMatch === $etag)
		{
			return $response
				->withStatus(304)
				->withHeader('Cache-Control', 'private, max-age=60, must-revalidate')
				->withHeader('ETag', $etag)
				->withHeader('Vary', 'Cookie')
				->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $lastModifiedTimestamp) . ' GMT');
		}

		$viewData = $this->BuildRecipeDetailsViewData($recipeId);

		if ($viewData['selectedRecipe'] === null)
		{
			return $response->withStatus(404);
		}

		$response = $this->renderPage($response, 'components.recipe_details_panel', $viewData);

		return $response
			->withHeader('Cache-Control', 'private, max-age=60, must-revalidate')
			->withHeader('ETag', $etag)
			->withHeader('Vary', 'Cookie')
			->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $lastModifiedTimestamp) . ' GMT');
	}

	private function ApplyOverviewSearchAndStatusFilters($recipesQuery, string $search, string $status)
	{
		if (!empty($search))
		{
			$matchingRecipeIds = $this->GetRecipeIdsMatchingSearch($search);
			if (count($matchingRecipeIds) === 0)
			{
				$recipesQuery = $recipesQuery->where('1 = 0');
			}
			else
			{
				$recipesQuery = $recipesQuery->where('id IN (' . implode(',', $matchingRecipeIds) . ')');
			}
		}

		if ($status === 'Xenoughinstock')
		{
			$recipesQuery = $recipesQuery->where('id IN (SELECT recipe_id FROM recipes_resolved WHERE need_fulfilled = 1)');
		}
		elseif ($status === 'enoughinstockwithshoppinglist')
		{
			$recipesQuery = $recipesQuery->where('id IN (SELECT recipe_id FROM recipes_resolved WHERE need_fulfilled = 0 AND need_fulfilled_with_shopping_list = 1)');
		}
		elseif ($status === 'notenoughinstock')
		{
			$recipesQuery = $recipesQuery->where('id IN (SELECT recipe_id FROM recipes_resolved WHERE need_fulfilled_with_shopping_list = 0)');
		}

		return $recipesQuery;
	}

	private function GetRecipeIdsMatchingSearch(string $search): array
	{
		$searchLike = '%' . $search . '%';
		$sql = 'SELECT DISTINCT r.id
			FROM recipes r
			LEFT JOIN recipes_resolved rr
				ON rr.recipe_id = r.id
			WHERE r.type = :recipe_type
				AND (r.name LIKE :search_like OR IFNULL(rr.product_names_comma_separated, \'\') LIKE :search_like)';

		$stmt = $this->getDatabaseService()->GetDbConnectionRaw()->prepare($sql);
		$stmt->execute([
			':recipe_type' => RecipesService::RECIPE_TYPE_NORMAL,
			':search_like' => $searchLike
		]);

		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		$ids = [];
		foreach ($rows as $row)
		{
			$ids[] = intval($row['id']);
		}

		return $ids;
	}

	private function BuildRecipeDetailsViewData(int $recipeId): array
	{
		$selectedRecipe = $this->getDatabase()->recipes()->where('id = :1 AND type = :2', $recipeId, RecipesService::RECIPE_TYPE_NORMAL)->fetch();
		if ($selectedRecipe === null)
		{
			return [
				'selectedRecipe' => null
			];
		}

		$selectedRecipeSubRecipes = $this->getDatabase()->recipes()
			->where('id IN (SELECT includes_recipe_id FROM recipes_nestings_resolved WHERE recipe_id = :1 AND includes_recipe_id != :1)', $selectedRecipe->id)
			->orderBy('name', 'COLLATE NOCASE')
			->fetchAll();

		$includedRecipeIdsAbsolute = [$selectedRecipe->id];
		foreach ($selectedRecipeSubRecipes as $subRecipe)
		{
			$includedRecipeIdsAbsolute[] = $subRecipe->id;
		}

		// Single batch fetch for all recipe positions across parent + sub-recipes.
		// Replaces 1 + N_sub + N_ingredients separate view scans (each involving a
		// recursive CTE + stock_current join) with one filtered query.
		$idList = implode(',', array_map('intval', $includedRecipeIdsAbsolute));
		$allPositionsBatch = $this->getDatabase()->recipes_pos_resolved()
			->where('recipe_id IN (' . $idList . ')')
			->fetchAll();

		// Build a map of nested override rows (parent-recipe view of sub-recipe ingredients)
		// keyed by recipe_pos_id for O(1) lookup when applying overrides below.
		$nestedOverrideMap = [];
		foreach ($allPositionsBatch as $pos)
		{
			if ($pos->recipe_id == $selectedRecipe->id && $pos->is_nested_recipe_pos == 1)
			{
				$nestedOverrideMap[$pos->recipe_pos_id] = $pos;
			}
		}

		// Derive $recipePositionsResolved (all rows for the selected recipe — used by
		// the missing-products list which needs both nested and non-nested rows).
		$recipePositionsResolved = [];
		foreach ($allPositionsBatch as $pos)
		{
			if ($pos->recipe_id == $selectedRecipe->id)
			{
				$recipePositionsResolved[] = $pos;
			}
		}

		// Derive $allRecipePositions: group non-nested rows by recipe_id, apply nested
		// overrides for sub-recipes, then sort by ingredient_group.
		$allRecipePositions = array_fill_keys($includedRecipeIdsAbsolute, []);
		foreach ($allPositionsBatch as $pos)
		{
			if ($pos->is_nested_recipe_pos == 0)
			{
				$allRecipePositions[$pos->recipe_id][] = $pos;
			}
		}
		foreach ($allRecipePositions as $id => &$positions)
		{
			if ($id != $selectedRecipe->id)
			{
				foreach ($positions as $pos)
				{
					if (isset($nestedOverrideMap[$pos->recipe_pos_id]))
					{
						$override = $nestedOverrideMap[$pos->recipe_pos_id];
						$pos->recipe_amount = $override->recipe_amount;
						$pos->missing_amount = $override->missing_amount;
					}
				}
			}
			usort($positions, function ($a, $b) {
				return strnatcasecmp((string)($a->ingredient_group ?? ''), (string)($b->ingredient_group ?? ''));
			});
		}
		unset($positions);

		$recipesResolved = $this->getRecipesService()->GetRecipesResolved('recipe_id IN (' . $idList . ')')->fetchAll();

		// Collect distinct product IDs used by this recipe so we can fetch only the
		// relevant rows from products and quantity_unit_conversions_resolved instead
		// of loading every row in those (potentially large) tables.
		$productIds = [];
		foreach ($allPositionsBatch as $pos)
		{
			$productIds[$pos->product_id] = true;
		}
		$productIds = array_keys($productIds);

		if (count($productIds) > 0)
		{
			$productIdList = implode(',', array_map('intval', $productIds));
			$products = $this->getDatabase()->products()->where('id IN (' . $productIdList . ')')->fetchAll();
			$quantityUnitConversionsResolved = $this->getDatabase()->cache__quantity_unit_conversions_resolved()->where('product_id IN (' . $productIdList . ')')->fetchAll();
		}
		else
		{
			$products = [];
			$quantityUnitConversionsResolved = [];
		}

		return [
			'selectedRecipe' => $selectedRecipe,
			'selectedRecipeSubRecipes' => $selectedRecipeSubRecipes,
			'includedRecipeIdsAbsolute' => $includedRecipeIdsAbsolute,
			'allRecipePositions' => $allRecipePositions,
			'recipesResolved' => $recipesResolved,
			'recipePositionsResolved' => $recipePositionsResolved,
			'products' => $products,
			'quantityUnits' => $this->getDatabase()->quantity_units()->fetchAll(),
			'quantityUnitConversionsResolved' => $quantityUnitConversionsResolved
		];
	}

	public function RecipeEditForm(Request $request, Response $response, array $args)
	{
		$recipeId = $args['recipeId'];

		return $this->renderPage($response, 'recipeform', [
			'recipe' => $this->getDatabase()->recipes($recipeId),
			'recipePositions' => $this->getDatabase()->recipes_pos()->where('recipe_id', $recipeId),
			'mode' => $recipeId == 'new' ? 'create' : 'edit',
			'products' => $this->getDatabase()->products()->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->getDatabase()->quantity_units(),
			'recipes' => $this->getDatabase()->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE'),
			'recipeNestings' => $this->getDatabase()->recipes_nestings()->where('recipe_id', $recipeId),
			'userfields' => $this->getUserfieldsService()->GetFields('recipes'),
			'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved()
		]);
	}

	public function RecipePosEditForm(Request $request, Response $response, array $args)
	{
		if ($args['recipePosId'] == 'new')
		{
			return $this->renderPage($response, 'recipeposform', [
				'mode' => 'create',
				'recipe' => $this->getDatabase()->recipes($args['recipeId']),
				'recipePos' => new \stdClass(),
				'products' => $this->getDatabase()->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->getDatabase()->product_barcodes_comma_separated(),
				'quantityUnits' => $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved()
			]);
		}
		else
		{
			return $this->renderPage($response, 'recipeposform', [
				'mode' => 'edit',
				'recipe' => $this->getDatabase()->recipes($args['recipeId']),
				'recipePos' => $this->getDatabase()->recipes_pos($args['recipePosId']),
				'products' => $this->getDatabase()->products()->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->getDatabase()->product_barcodes_comma_separated(),
				'quantityUnits' => $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved()
			]);
		}
	}

	public function RecipesSettings(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'recipessettings');
	}

	public function MealPlanSectionEditForm(Request $request, Response $response, array $args)
	{
		if ($args['sectionId'] == 'new')
		{
			return $this->renderPage($response, 'mealplansectionform', [
				'mode' => 'create'
			]);
		}
		else
		{
			return $this->renderPage($response, 'mealplansectionform', [
				'mealplanSection' => $this->getDatabase()->meal_plan_sections($args['sectionId']),
				'mode' => 'edit'
			]);
		}
	}

	public function MealPlanSectionsList(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'mealplansections', [
			'mealplanSections' => $this->getDatabase()->meal_plan_sections()->where('id > 0')->orderBy('sort_number')
		]);
	}

	public function RecipeGrocycodeImage(Request $request, Response $response, array $args)
	{
		$gc = new Grocycode(Grocycode::RECIPE, $args['recipeId']);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}
}
