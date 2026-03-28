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

		$recipesCountQuery = $this->getDatabase()->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL);
		$this->ApplyOverviewSearchAndStatusFilters($recipesCountQuery, $search, $status);
		$totalRecipes = intval($recipesCountQuery->count('*'));
		$totalPages = max(intval(ceil($totalRecipes / $pageSize)), 1);
		if ($page > $totalPages)
		{
			$page = $totalPages;
		}

		$offset = ($page - 1) * $pageSize;
		$recipesQuery = $this->getDatabase()->recipes()
			->where('type', RecipesService::RECIPE_TYPE_NORMAL)
			->orderBy('name', 'COLLATE NOCASE');
		$this->ApplyOverviewSearchAndStatusFilters($recipesQuery, $search, $status);
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

	private function ApplyOverviewSearchAndStatusFilters($recipesQuery, string $search, string $status): void
	{
		if (!empty($search))
		{
			$searchLike = '%' . $search . '%';
			$recipesQuery->where('(name LIKE :1 OR id IN (SELECT recipe_id FROM recipes_resolved WHERE product_names_comma_separated LIKE :2))', $searchLike, $searchLike);
		}

		if ($status === 'Xenoughinstock')
		{
			$recipesQuery->where('id IN (SELECT recipe_id FROM recipes_resolved WHERE need_fulfilled = 1)');
		}
		elseif ($status === 'enoughinstockwithshoppinglist')
		{
			$recipesQuery->where('id IN (SELECT recipe_id FROM recipes_resolved WHERE need_fulfilled = 0 AND need_fulfilled_with_shopping_list = 1)');
		}
		elseif ($status === 'notenoughinstock')
		{
			$recipesQuery->where('id IN (SELECT recipe_id FROM recipes_resolved WHERE need_fulfilled_with_shopping_list = 0)');
		}
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

		$recipePositionsResolved = $this->getDatabase()->recipes_pos_resolved()->where('recipe_id', $selectedRecipe->id)->fetchAll();

		$selectedRecipeSubRecipes = $this->getDatabase()->recipes()
			->where('id IN (SELECT includes_recipe_id FROM recipes_nestings_resolved WHERE recipe_id = :1 AND includes_recipe_id != :1)', $selectedRecipe->id)
			->orderBy('name', 'COLLATE NOCASE')
			->fetchAll();

		$includedRecipeIdsAbsolute = [];
		$includedRecipeIdsAbsolute[] = $selectedRecipe->id;
		foreach ($selectedRecipeSubRecipes as $subRecipe)
		{
			$includedRecipeIdsAbsolute[] = $subRecipe->id;
		}

		$recipesResolved = $this->getRecipesService()->GetRecipesResolved('recipe_id IN (' . implode(',', array_map('intval', $includedRecipeIdsAbsolute)) . ')')->fetchAll();

		$allRecipePositions = [];
		foreach ($includedRecipeIdsAbsolute as $id)
		{
			$allRecipePositions[$id] = $this->getDatabase()->recipes_pos_resolved()->where('recipe_id = :1 AND is_nested_recipe_pos = 0', $id)->orderBy('ingredient_group', 'ASC', 'product_group', 'ASC')->fetchAll();
			foreach ($allRecipePositions[$id] as $pos)
			{
				if ($id != $selectedRecipe->id)
				{
					$pos2 = $this->getDatabase()->recipes_pos_resolved()->where('recipe_id = :1  AND recipe_pos_id = :2 AND is_nested_recipe_pos = 1', $selectedRecipe->id, $pos->recipe_pos_id)->fetch();
					if ($pos2 !== null)
					{
						$pos->recipe_amount = $pos2->recipe_amount;
						$pos->missing_amount = $pos2->missing_amount;
					}
				}
			}
		}

		return [
			'selectedRecipe' => $selectedRecipe,
			'selectedRecipeSubRecipes' => $selectedRecipeSubRecipes,
			'includedRecipeIdsAbsolute' => $includedRecipeIdsAbsolute,
			'allRecipePositions' => $allRecipePositions,
			'recipesResolved' => $recipesResolved,
			'recipePositionsResolved' => $recipePositionsResolved,
			'products' => $this->getDatabase()->products()->fetchAll(),
			'quantityUnits' => $this->getDatabase()->quantity_units()->fetchAll(),
			'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved()->fetchAll()
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
