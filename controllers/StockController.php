<?php

namespace Grocy\Controllers;

use Grocy\Helpers\Grocycode;
use Grocy\Services\RecipesService;
use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StockController extends BaseController
{
	use GrocycodeTrait;

	private const STOCK_OVERVIEW_PAGE_SIZE_DEFAULT = 100;
	private const STOCK_OVERVIEW_PAGE_SIZE_MAX = 200;
	private const STOCK_OVERVIEW_SORT_DEFAULT = 'next_due_date';

	public function __construct(Container $container)
	{
		parent::__construct($container);

		try
		{
			$externalBarcodeLookupPluginName = $this->getStockService()->GetExternalBarcodeLookupPluginName();
		}
		catch (\Exception)
		{
			$externalBarcodeLookupPluginName = '';
		}
		finally
		{
			$this->View->set('ExternalBarcodeLookupPluginName', $externalBarcodeLookupPluginName);
		}
	}

	public function Consume(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'consume', [
			'products' => $this->getDatabase()->products()->where('active = 1')->where('id IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)')->orderBy('name'),
			'barcodes' => $this->getDatabase()->product_barcodes_comma_separated(),
			'recipes' => $this->getDatabase()->recipes()->where('type', RecipesService::RECIPE_TYPE_NORMAL)->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->getDatabase()->locations()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved()
		]);
	}

	public function Inventory(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'inventory', [
			'products' => $this->getDatabase()->products()->where('active = 1 AND no_own_stock = 0')->orderBy('name', 'COLLATE NOCASE'),
			'barcodes' => $this->getDatabase()->product_barcodes_comma_separated(),
			'shoppinglocations' => $this->getDatabase()->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->getDatabase()->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved(),
			'userfields' => $this->getUserfieldsService()->GetFields('stock')
		]);
	}

	public function Journal(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['months']) && filter_var($request->getQueryParams()['months'], FILTER_VALIDATE_INT) !== false)
		{
			$months = $request->getQueryParams()['months'];
			$where = "row_created_timestamp > DATE(DATE('now', 'localtime'), '-$months months')";
		}
		else
		{
			// Default 1 month
			$where = "row_created_timestamp > DATE(DATE('now', 'localtime'), '-1 months')";
		}

		if (isset($request->getQueryParams()['product']) && filter_var($request->getQueryParams()['product'], FILTER_VALIDATE_INT) !== false)
		{
			$productId = $request->getQueryParams()['product'];
			$where .= " AND product_id = $productId";
		}

		$usersService = $this->getUsersService();

		return $this->renderPage($response, 'stockjournal', [
			'stockLog' => $this->getDatabase()->uihelper_stock_journal()->where($where)->orderBy('row_created_timestamp', 'DESC'),
			'products' => $this->getDatabase()->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->getDatabase()->locations()->orderBy('name', 'COLLATE NOCASE'),
			'users' => $usersService->GetUsersAsDto(),
			'transactionTypes' => GetClassConstants('\Grocy\Services\StockService', 'TRANSACTION_TYPE_'),
			'userfieldsStock' => $this->getUserfieldsService()->GetFields('stock'),
			'userfieldValuesStock' => $this->getUserfieldsService()->GetAllValues('stock')
		]);
	}

	public function LocationContentSheet(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'locationcontentsheet', [
			'products' => $this->getDatabase()->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->getDatabase()->locations()->orderBy('name', 'COLLATE NOCASE'),
			'currentStockLocationContent' => $this->getStockService()->GetCurrentStockLocationContent(isset($request->getQueryParams()['include_out_of_stock']))
		]);
	}

	public function LocationEditForm(Request $request, Response $response, array $args)
	{
		if ($args['locationId'] == 'new')
		{
			return $this->renderPage($response, 'locationform', [
				'mode' => 'create',
				'userfields' => $this->getUserfieldsService()->GetFields('locations')
			]);
		}
		else
		{
			return $this->renderPage($response, 'locationform', [
				'location' => $this->getDatabase()->locations($args['locationId']),
				'mode' => 'edit',
				'userfields' => $this->getUserfieldsService()->GetFields('locations')
			]);
		}
	}

	public function LocationsList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$locations = $this->getDatabase()->locations()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$locations = $this->getDatabase()->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->renderPage($response, 'locations', [
			'locations' => $locations,
			'userfields' => $this->getUserfieldsService()->GetFields('locations'),
			'userfieldValues' => $this->getUserfieldsService()->GetAllValues('locations')
		]);
	}

	public function Overview(Request $request, Response $response, array $args)
	{
		$usersService = $this->getUsersService();
		$userSettings = $usersService->GetUserSettings(GROCY_USER_ID);
		$nextXDays = $userSettings['stock_due_soon_days'];
		$queryParams = $request->getQueryParams();

		$filters = [
			'search' => trim($queryParams['search'] ?? ''),
			'location' => $this->NormalizeStockOverviewFilterValue($queryParams['location'] ?? ''),
			'product_group' => $this->NormalizeStockOverviewFilterValue($queryParams['product_group'] ?? ''),
			'status' => $this->NormalizeStockOverviewFilterValue($queryParams['status'] ?? '')
		];
		$sort = $this->NormalizeStockOverviewSortColumn($queryParams['sort'] ?? self::STOCK_OVERVIEW_SORT_DEFAULT);
		$sortDirection = $this->NormalizeStockOverviewSortDirection($queryParams['direction'] ?? 'asc');

		$page = intval($queryParams['page'] ?? 1);
		if ($page < 1)
		{
			$page = 1;
		}

		$pageSize = intval($queryParams['page_size'] ?? self::STOCK_OVERVIEW_PAGE_SIZE_DEFAULT);
		if ($pageSize < 1)
		{
			$pageSize = self::STOCK_OVERVIEW_PAGE_SIZE_DEFAULT;
		}
		if ($pageSize > self::STOCK_OVERVIEW_PAGE_SIZE_MAX)
		{
			$pageSize = self::STOCK_OVERVIEW_PAGE_SIZE_MAX;
		}

		$where = 'is_in_stock_or_below_min_stock = 1';
		if (boolval($userSettings['stock_overview_show_all_out_of_stock_products']))
		{
			$where = '1=1';
		}

		$currentStockCountQuery = $this->BuildStockOverviewQuery($where, $filters, $nextXDays);
		$totalProducts = intval($currentStockCountQuery->count('*'));
		$totalPages = max(intval(ceil($totalProducts / $pageSize)), 1);
		if ($page > $totalPages)
		{
			$page = $totalPages;
		}

		$offset = ($page - 1) * $pageSize;
		$currentStockQuery = $this->BuildStockOverviewQuery($where, $filters, $nextXDays);
		$currentStock = $this->ApplyStockOverviewSorting($currentStockQuery, $sort, $sortDirection)
			->limit($pageSize, $offset)
			->fetchAll();

		$productIds = [];
		foreach ($currentStock as $currentStockEntry)
		{
			$productIds[] = intval($currentStockEntry->product_id);
		}

		$userfieldValues = [];
		if (count($productIds) > 0)
		{
			$userfieldValues = $this->getDatabase()->userfield_values_resolved()
				->where('entity = :1 AND object_id IN (' . implode(',', $productIds) . ')', 'products')
				->orderBy('name', 'COLLATE NOCASE')
				->fetchAll();
		}

		$firstItem = 0;
		$lastItem = 0;
		if ($totalProducts > 0)
		{
			$firstItem = $offset + 1;
			$lastItem = min($offset + count($currentStock), $totalProducts);
		}

		return $this->renderPage($response, 'stockoverview', [
			'currentStock' => $currentStock,
			'locations' => $this->getDatabase()->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'nextXDays' => $nextXDays,
			'productGroups' => $this->getDatabase()->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => $this->getUserfieldsService()->GetFields('products'),
			'userfieldValues' => $userfieldValues,
			'filters' => $filters,
			'sorting' => [
				'sort' => $sort,
				'direction' => $sortDirection
			],
			'pagination' => [
				'page' => $page,
				'pageSize' => $pageSize,
				'totalProducts' => $totalProducts,
				'totalPages' => $totalPages,
				'hasPreviousPage' => $page > 1,
				'hasNextPage' => $page < $totalPages,
				'previousPage' => max($page - 1, 1),
				'nextPage' => min($page + 1, $totalPages),
				'firstItem' => $firstItem,
				'lastItem' => $lastItem
			],
			'queryParams' => $queryParams
		]);
	}

	private function BuildStockOverviewQuery(string $baseWhere, array $filters, int $nextXDays)
	{
		$query = $this->getDatabase()->uihelper_stock_current_overview()->where($baseWhere);

		if (!empty($filters['search']))
		{
			$searchTerm = '%' . $filters['search'] . '%';
			$query = $query->where(
				'product_name LIKE :1 OR IFNULL(product_barcodes, \'\') LIKE :2 OR IFNULL(product_group_name, \'\') LIKE :3 OR IFNULL(product_description, \'\') LIKE :4 OR IFNULL(parent_product_name, \'\') LIKE :5 OR IFNULL(product_default_location_name, \'\') LIKE :6 OR IFNULL(default_store_name, \'\') LIKE :7',
				$searchTerm,
				$searchTerm,
				$searchTerm,
				$searchTerm,
				$searchTerm,
				$searchTerm,
				$searchTerm
			);
		}

		if (!empty($filters['location']))
		{
			$matchingProductIds = $this->GetStockOverviewProductIdsForLocation($filters['location']);
			if (count($matchingProductIds) === 0)
			{
				$query = $query->where('1 = 0');
			}
			else
			{
				$query = $query->where('product_id IN (' . implode(',', $matchingProductIds) . ')');
			}
		}

		if (!empty($filters['product_group']))
		{
			$query = $query->where('product_group_name = :1', $filters['product_group']);
		}

		return $this->ApplyStockOverviewStatusFilter($query, $filters['status'], $nextXDays);
	}

	private function ApplyStockOverviewStatusFilter($query, string $status, int $nextXDays)
	{
		if ($status === 'expired')
		{
			return $query->where('best_before_date <= :1 AND amount > 0 AND due_type = 2', date('Y-m-d', strtotime('-1 days')));
		}

		if ($status === 'overdue')
		{
			return $query->where('best_before_date <= :1 AND amount > 0 AND due_type = 1', date('Y-m-d', strtotime('-1 days')));
		}

		if ($status === 'duesoon')
		{
			return $query->where('best_before_date >= :1 AND best_before_date <= :2 AND amount > 0', date('Y-m-d'), date('Y-m-d', strtotime('+' . $nextXDays . ' days')));
		}

		if ($status === 'instockX')
		{
			return $query->where('amount_aggregated > 0');
		}

		if ($status === 'belowminstockamount')
		{
			return $query->where('product_missing = 1');
		}

		return $query;
	}

	private function GetStockOverviewProductIdsForLocation(string $locationName): array
	{
		$location = $this->getDatabase()->locations()->where('active = 1 AND name = :1', $locationName)->fetch();
		if ($location === null)
		{
			return [];
		}

		$productIds = [];
		foreach ($this->getStockService()->GetCurrentStockLocations() as $currentStockLocation)
		{
			if (intval($currentStockLocation->location_id) !== intval($location->id))
			{
				continue;
			}

			$productIds[intval($currentStockLocation->product_id)] = intval($currentStockLocation->product_id);
		}

		return array_values($productIds);
	}

	private function ApplyStockOverviewSorting($query, string $sort, string $sortDirection)
	{
		$sortDirectionSql = strtoupper($sortDirection);

		switch ($sort)
		{
			case 'product':
				$query = $query->orderBy('product_name', 'COLLATE NOCASE ' . $sortDirectionSql);
				break;
			case 'product_group':
				$query = $query->orderBy('product_group_name', 'COLLATE NOCASE ' . $sortDirectionSql);
				break;
			case 'amount':
				$query = $query->orderBy('amount', $sortDirectionSql);
				break;
			case 'value':
				$query = $query->orderBy('value', $sortDirectionSql);
				break;
			case 'calories_per_unit':
				$query = $query->orderBy('product_calories', $sortDirectionSql);
				break;
			case 'calories':
				$query = $query->orderBy('calories', $sortDirectionSql);
				break;
			case 'last_purchased':
				$query = $query->orderBy('last_purchased', $sortDirectionSql);
				break;
			case 'last_price':
				$query = $query->orderBy('last_price', $sortDirectionSql);
				break;
			case 'min_stock_amount':
				$query = $query->orderBy('min_stock_amount', $sortDirectionSql);
				break;
			case 'product_description':
				$query = $query->orderBy('product_description', 'COLLATE NOCASE ' . $sortDirectionSql);
				break;
			case 'parent_product':
				$query = $query->orderBy('parent_product_name', 'COLLATE NOCASE ' . $sortDirectionSql);
				break;
			case 'default_location':
				$query = $query->orderBy('product_default_location_name', 'COLLATE NOCASE ' . $sortDirectionSql);
				break;
			case 'average_price':
				$query = $query->orderBy('average_price', $sortDirectionSql);
				break;
			case 'default_store':
				$query = $query->orderBy('default_store_name', 'COLLATE NOCASE ' . $sortDirectionSql);
				break;
			case 'next_due_date':
			default:
				$query = $query->orderBy('best_before_date', $sortDirectionSql);
				break;
		}

		if ($sort !== 'product')
		{
			$query = $query->orderBy('product_name', 'COLLATE NOCASE ASC');
		}

		return $query;
	}

	private function NormalizeStockOverviewFilterValue(string $value): string
	{
		$value = trim($value);
		if ($value === 'all')
		{
			return '';
		}

		return $value;
	}

	private function NormalizeStockOverviewSortColumn(string $value): string
	{
		$allowedSortColumns = [
			'product',
			'product_group',
			'amount',
			'value',
			'next_due_date',
			'calories_per_unit',
			'calories',
			'last_purchased',
			'last_price',
			'min_stock_amount',
			'product_description',
			'parent_product',
			'default_location',
			'average_price',
			'default_store'
		];

		if (!in_array($value, $allowedSortColumns))
		{
			return self::STOCK_OVERVIEW_SORT_DEFAULT;
		}

		return $value;
	}

	private function NormalizeStockOverviewSortDirection(string $value): string
	{
		$value = strtolower(trim($value));
		if ($value !== 'desc')
		{
			return 'asc';
		}

		return 'desc';
	}

	public function ProductBarcodesEditForm(Request $request, Response $response, array $args)
	{
		$product = null;
		if (isset($request->getQueryParams()['product']))
		{
			$product = $this->getDatabase()->products($request->getQueryParams()['product']);
		}

		if ($args['productBarcodeId'] == 'new')
		{
			return $this->renderPage($response, 'productbarcodeform', [
				'mode' => 'create',
				'barcodes' => $this->getDatabase()->product_barcodes()->orderBy('barcode'),
				'product' => $product,
				'shoppinglocations' => $this->getDatabase()->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnits' => $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved(),
				'userfields' => $this->getUserfieldsService()->GetFields('product_barcodes')
			]);
		}
		else
		{
			return $this->renderPage($response, 'productbarcodeform', [
				'mode' => 'edit',
				'barcode' => $this->getDatabase()->product_barcodes($args['productBarcodeId']),
				'product' => $product,
				'shoppinglocations' => $this->getDatabase()->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnits' => $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved(),
				'userfields' => $this->getUserfieldsService()->GetFields('product_barcodes')
			]);
		}
	}

	public function ProductEditForm(Request $request, Response $response, array $args)
	{
		if ($args['productId'] == 'new')
		{
			$quantityunits = $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');

			return $this->renderPage($response, 'productform', [
				'locations' => $this->getDatabase()->locations()->where('active = 1')->orderBy('name'),
				'barcodes' => $this->getDatabase()->product_barcodes()->orderBy('barcode'),
				'quantityunitsAll' => $quantityunits,
				'quantityunitsReferenced' => $quantityunits,
				'shoppinglocations' => $this->getDatabase()->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'productgroups' => $this->getDatabase()->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'userfields' => $this->getUserfieldsService()->GetFields('products'),
				'products' => $this->getDatabase()->products()->where('parent_product_id IS NULL and active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'isSubProductOfOthers' => false,
				'mode' => 'create'
			]);
		}
		else
		{
			$product = $this->getDatabase()->products($args['productId']);

			return $this->renderPage($response, 'productform', [
				'product' => $product,
				'locations' => $this->getDatabase()->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->getDatabase()->product_barcodes()->orderBy('barcode'),
				'quantityunitsAll' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityunitsReferenced' => $this->getDatabase()->quantity_units()->where('id IN (SELECT to_qu_id FROM cache__quantity_unit_conversions_resolved WHERE product_id = :1) OR NOT EXISTS(SELECT 1 FROM stock_log WHERE product_id = :1)', $product->id)->orderBy('name', 'COLLATE NOCASE'),
				'shoppinglocations' => $this->getDatabase()->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'productgroups' => $this->getDatabase()->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'userfields' => $this->getUserfieldsService()->GetFields('products'),
				'products' => $this->getDatabase()->products()->where('id != :1 AND parent_product_id IS NULL and active = 1', $product->id)->orderBy('name', 'COLLATE NOCASE'),
				'isSubProductOfOthers' => $this->getDatabase()->products()->where('parent_product_id = :1', $product->id)->count() !== 0,
				'mode' => 'edit',
				'quConversions' => $this->getDatabase()->quantity_unit_conversions()->where('product_id', $product->id),
				'productBarcodeUserfields' => $this->getUserfieldsService()->GetFields('product_barcodes'),
				'productBarcodeUserfieldValues' => $this->getUserfieldsService()->GetAllValues('product_barcodes')
			]);
		}
	}

	public function ProductGrocycodeImage(Request $request, Response $response, array $args)
	{
		$gc = new Grocycode(Grocycode::PRODUCT, $args['productId']);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}

	public function ProductGroupEditForm(Request $request, Response $response, array $args)
	{
		if ($args['productGroupId'] == 'new')
		{
			return $this->renderPage($response, 'productgroupform', [
				'mode' => 'create',
				'userfields' => $this->getUserfieldsService()->GetFields('product_groups')
			]);
		}
		else
		{
			return $this->renderPage($response, 'productgroupform', [
				'group' => $this->getDatabase()->product_groups($args['productGroupId']),
				'mode' => 'edit',
				'userfields' => $this->getUserfieldsService()->GetFields('product_groups')
			]);
		}
	}

	public function ProductGroupsList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$productGroups = $this->getDatabase()->product_groups()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$productGroups = $this->getDatabase()->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->renderPage($response, 'productgroups', [
			'productGroups' => $productGroups,
			'products' => $this->getDatabase()->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => $this->getUserfieldsService()->GetFields('product_groups'),
			'userfieldValues' => $this->getUserfieldsService()->GetAllValues('product_groups')
		]);
	}

	public function ProductsList(Request $request, Response $response, array $args)
	{
		$products = $this->getDatabase()->products();
		if (!isset($request->getQueryParams()['include_disabled']))
		{
			$products = $products->where('active = 1');
		}

		if (isset($request->getQueryParams()['only_in_stock']))
		{
			$products = $products->where('id IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)');
		}
		if (isset($request->getQueryParams()['only_out_of_stock']))
		{
			$products = $products->where('id NOT IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)');
		}

		$products = $products->orderBy('name', 'COLLATE NOCASE');

		return $this->renderPage($response, 'products', [
			'products' => $products,
			'locations' => $this->getDatabase()->locations()->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'productGroups' => $this->getDatabase()->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'shoppingLocations' => $this->getDatabase()->shopping_locations()->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => $this->getUserfieldsService()->GetFields('products'),
			'userfieldValues' => $this->getUserfieldsService()->GetAllValues('products')
		]);
	}

	public function Purchase(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'purchase', [
			'products' => $this->getDatabase()->products()->where('active = 1 AND no_own_stock = 0')->orderBy('name', 'COLLATE NOCASE'),
			'barcodes' => $this->getDatabase()->product_barcodes_comma_separated(),
			'shoppinglocations' => $this->getDatabase()->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->getDatabase()->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved(),
			'userfields' => $this->getUserfieldsService()->GetFields('stock')
		]);
	}

	public function QuantityUnitConversionEditForm(Request $request, Response $response, array $args)
	{
		$product = null;
		if (isset($request->getQueryParams()['product']))
		{
			$product = $this->getDatabase()->products($request->getQueryParams()['product']);
		}

		$defaultQuUnit = null;

		if (isset($request->getQueryParams()['qu-unit']))
		{
			$defaultQuUnit = $this->getDatabase()->quantity_units($request->getQueryParams()['qu-unit']);
		}

		if ($args['quConversionId'] == 'new')
		{
			return $this->renderPage($response, 'quantityunitconversionform', [
				'mode' => 'create',
				'userfields' => $this->getUserfieldsService()->GetFields('quantity_unit_conversions'),
				'quantityunits' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'product' => $product,
				'defaultQuUnit' => $defaultQuUnit
			]);
		}
		else
		{
			return $this->renderPage($response, 'quantityunitconversionform', [
				'quConversion' => $this->getDatabase()->quantity_unit_conversions($args['quConversionId']),
				'mode' => 'edit',
				'userfields' => $this->getUserfieldsService()->GetFields('quantity_unit_conversions'),
				'quantityunits' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'product' => $product,
				'defaultQuUnit' => $defaultQuUnit
			]);
		}
	}

	public function QuantityUnitEditForm(Request $request, Response $response, array $args)
	{
		if ($args['quantityunitId'] == 'new')
		{
			return $this->renderPage($response, 'quantityunitform', [
				'mode' => 'create',
				'userfields' => $this->getUserfieldsService()->GetFields('quantity_units'),
				'pluralCount' => $this->getLocalizationService()->GetPluralCount(),
				'pluralRule' => $this->getLocalizationService()->GetPluralDefinition()
			]);
		}
		else
		{
			$quantityUnit = $this->getDatabase()->quantity_units($args['quantityunitId']);

			return $this->renderPage($response, 'quantityunitform', [
				'quantityUnit' => $quantityUnit,
				'mode' => 'edit',
				'userfields' => $this->getUserfieldsService()->GetFields('quantity_units'),
				'pluralCount' => $this->getLocalizationService()->GetPluralCount(),
				'pluralRule' => $this->getLocalizationService()->GetPluralDefinition(),
				'defaultQuConversions' => $this->getDatabase()->quantity_unit_conversions()->where('from_qu_id = :1 AND product_id IS NULL', $quantityUnit->id),
				'quantityUnits' => $this->getDatabase()->quantity_units()
			]);
		}
	}

	public function QuantityUnitPluralFormTesting(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'quantityunitpluraltesting', [
			'quantityUnits' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE')
		]);
	}

	public function QuantityUnitsList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$quantityUnits = $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$quantityUnits = $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->renderPage($response, 'quantityunits', [
			'quantityunits' => $quantityUnits,
			'userfields' => $this->getUserfieldsService()->GetFields('quantity_units'),
			'userfieldValues' => $this->getUserfieldsService()->GetAllValues('quantity_units')
		]);
	}

	public function ShoppingList(Request $request, Response $response, array $args)
	{
		$listId = 1;
		if (isset($request->getQueryParams()['list']))
		{
			$listId = $request->getQueryParams()['list'];
		}

		return $this->renderPage($response, 'shoppinglist', [
			'listItems' => $this->getDatabase()->uihelper_shopping_list()->where('shopping_list_id = :1', $listId)->orderBy('product_name', 'COLLATE NOCASE'),
			'products' => $this->getDatabase()->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->getDatabase()->quantity_units()->orderBy('name', 'COLLATE NOCASE'),
			'missingProducts' => $this->getStockService()->GetMissingProducts(),
			'shoppingLists' => $this->getDatabase()->shopping_lists_view()->orderBy('name', 'COLLATE NOCASE'),
			'selectedShoppingListId' => $listId,
			'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved(),
			'productUserfields' => $this->getUserfieldsService()->GetFields('products'),
			'productUserfieldValues' => $this->getUserfieldsService()->GetAllValues('products'),
			'productGroupUserfields' => $this->getUserfieldsService()->GetFields('product_groups'),
			'productGroupUserfieldValues' => $this->getUserfieldsService()->GetAllValues('product_groups'),
			'userfields' => $this->getUserfieldsService()->GetFields('shopping_list'),
			'userfieldValues' => $this->getUserfieldsService()->GetAllValues('shopping_list')
		]);
	}

	public function ShoppingListEditForm(Request $request, Response $response, array $args)
	{
		if ($args['listId'] == 'new')
		{
			return $this->renderPage($response, 'shoppinglistform', [
				'mode' => 'create',
				'userfields' => $this->getUserfieldsService()->GetFields('shopping_lists')
			]);
		}
		else
		{
			return $this->renderPage($response, 'shoppinglistform', [
				'shoppingList' => $this->getDatabase()->shopping_lists($args['listId']),
				'mode' => 'edit',
				'userfields' => $this->getUserfieldsService()->GetFields('shopping_lists')
			]);
		}
	}

	public function ShoppingListItemEditForm(Request $request, Response $response, array $args)
	{
		if ($args['itemId'] == 'new')
		{
			return $this->renderPage($response, 'shoppinglistitemform', [
				'products' => $this->getDatabase()->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->getDatabase()->product_barcodes_comma_separated(),
				'shoppingLists' => $this->getDatabase()->shopping_lists()->orderBy('name', 'COLLATE NOCASE'),
				'mode' => 'create',
				'quantityUnits' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved(),
				'userfields' => $this->getUserfieldsService()->GetFields('shopping_list')
			]);
		}
		else
		{
			return $this->renderPage($response, 'shoppinglistitemform', [
				'listItem' => $this->getDatabase()->shopping_list($args['itemId']),
				'products' => $this->getDatabase()->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'barcodes' => $this->getDatabase()->product_barcodes_comma_separated(),
				'shoppingLists' => $this->getDatabase()->shopping_lists()->orderBy('name', 'COLLATE NOCASE'),
				'mode' => 'edit',
				'quantityUnits' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
				'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved(),
				'userfields' => $this->getUserfieldsService()->GetFields('shopping_list')
			]);
		}
	}

	public function ShoppingListSettings(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'shoppinglistsettings', [
			'shoppingLists' => $this->getDatabase()->shopping_lists()->orderBy('name', 'COLLATE NOCASE')
		]);
	}

	public function ShoppingLocationEditForm(Request $request, Response $response, array $args)
	{
		if ($args['shoppingLocationId'] == 'new')
		{
			return $this->renderPage($response, 'shoppinglocationform', [
				'mode' => 'create',
				'userfields' => $this->getUserfieldsService()->GetFields('shopping_locations')
			]);
		}
		else
		{
			return $this->renderPage($response, 'shoppinglocationform', [
				'shoppingLocation' => $this->getDatabase()->shopping_locations($args['shoppingLocationId']),
				'mode' => 'edit',
				'userfields' => $this->getUserfieldsService()->GetFields('shopping_locations')
			]);
		}
	}

	public function ShoppingLocationsList(Request $request, Response $response, array $args)
	{
		if (isset($request->getQueryParams()['include_disabled']))
		{
			$shoppingLocations = $this->getDatabase()->shopping_locations()->orderBy('name', 'COLLATE NOCASE');
		}
		else
		{
			$shoppingLocations = $this->getDatabase()->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE');
		}

		return $this->renderPage($response, 'shoppinglocations', [
			'shoppinglocations' => $shoppingLocations,
			'userfields' => $this->getUserfieldsService()->GetFields('shopping_locations'),
			'userfieldValues' => $this->getUserfieldsService()->GetAllValues('shopping_locations')
		]);
	}

	public function StockEntryEditForm(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'stockentryform', [
			'stockEntry' => $this->getDatabase()->stock()->where('id', $args['entryId'])->fetch(),
			'products' => $this->getDatabase()->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'shoppinglocations' => $this->getDatabase()->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->getDatabase()->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'userfields' => $this->getUserfieldsService()->GetFields('stock')
		]);
	}

	public function StockEntryGrocycodeImage(Request $request, Response $response, array $args)
	{
		$stockEntry = $this->getDatabase()->stock()->where('id', $args['entryId'])->fetch();
		$gc = new Grocycode(Grocycode::PRODUCT, $stockEntry->product_id, [$stockEntry->stock_id]);
		return $this->ServeGrocycodeImage($request, $response, $gc);
	}

	public function StockEntryGrocycodeLabel(Request $request, Response $response, array $args)
	{
		$stockEntry = $this->getDatabase()->stock()->where('id', $args['entryId'])->fetch();
		return $this->renderPage($response, 'stockentrylabel', [
			'stockEntry' => $stockEntry,
			'product' => $this->getDatabase()->products($stockEntry->product_id),
		]);
	}

	public function StockSettings(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'stocksettings', [
			'locations' => $this->getDatabase()->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'productGroups' => $this->getDatabase()->product_groups()->where('active = 1')->orderBy('name', 'COLLATE NOCASE')
		]);
	}

	public function Stockentries(Request $request, Response $response, array $args)
	{
		$usersService = $this->getUsersService();
		$nextXDays = $usersService->GetUserSettings(GROCY_USER_ID)['stock_due_soon_days'];

		return $this->renderPage($response, 'stockentries', [
			'products' => $this->getDatabase()->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityunits' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'locations' => $this->getDatabase()->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'shoppinglocations' => $this->getDatabase()->shopping_locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'stockEntries' => $this->getDatabase()->uihelper_stock_entries()->orderBy('product_id'),
			'currentStockLocations' => $this->getStockService()->GetCurrentStockLocations(),
			'nextXDays' => $nextXDays,
			'userfieldsProducts' => $this->getUserfieldsService()->GetFields('products'),
			'userfieldValuesProducts' => $this->getUserfieldsService()->GetAllValues('products'),
			'userfieldsStock' => $this->getUserfieldsService()->GetFields('stock'),
			'userfieldValuesStock' => $this->getUserfieldsService()->GetAllValues('stock')
		]);
	}

	public function Transfer(Request $request, Response $response, array $args)
	{
		return $this->renderPage($response, 'transfer', [
			'products' => $this->getDatabase()->products()->where('active = 1')->where('no_own_stock = 0 AND id IN (SELECT product_id from stock_current WHERE amount_aggregated > 0)')->orderBy('name', 'COLLATE NOCASE'),
			'barcodes' => $this->getDatabase()->product_barcodes_comma_separated(),
			'locations' => $this->getDatabase()->locations()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnits' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $this->getDatabase()->cache__quantity_unit_conversions_resolved()
		]);
	}

	public function JournalSummary(Request $request, Response $response, array $args)
	{
		$entries = $this->getDatabase()->uihelper_stock_journal_summary();
		if (isset($request->getQueryParams()['product_id']))
		{
			$entries = $entries->where('product_id', $request->getQueryParams()['product_id']);
		}
		if (isset($request->getQueryParams()['user_id']))
		{
			$entries = $entries->where('user_id', $request->getQueryParams()['user_id']);
		}
		if (isset($request->getQueryParams()['transaction_type']))
		{
			$entries = $entries->where('transaction_type', $request->getQueryParams()['transaction_type']);
		}

		$usersService = $this->getUsersService();
		return $this->renderPage($response, 'stockjournalsummary', [
			'entries' => $entries,
			'products' => $this->getDatabase()->products()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'users' => $usersService->GetUsersAsDto(),
			'transactionTypes' => GetClassConstants('\Grocy\Services\StockService', 'TRANSACTION_TYPE_')
		]);
	}

	public function QuantityUnitConversionsResolved(Request $request, Response $response, array $args)
	{
		$product = null;
		if (isset($request->getQueryParams()['product']))
		{
			$product = $this->getDatabase()->products($request->getQueryParams()['product']);
			$quantityUnitConversionsResolved = $this->getDatabase()->cache__quantity_unit_conversions_resolved()->where('product_id', $product->id);
		}
		else
		{
			$quantityUnitConversionsResolved = $this->getDatabase()->cache__quantity_unit_conversions_resolved()->where('product_id IS NULL');
		}

		return $this->renderPage($response, 'quantityunitconversionsresolved', [
			'product' => $product,
			'quantityUnits' => $this->getDatabase()->quantity_units()->where('active = 1')->orderBy('name', 'COLLATE NOCASE'),
			'quantityUnitConversionsResolved' => $quantityUnitConversionsResolved
		]);
	}
}
