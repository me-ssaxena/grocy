@php require_frontend_packages(['datatables']); @endphp

@extends('layout.default')

@section('title', $__t('Recipes'))

@push('pageStyles')
<style>
	.card-img-top {
		max-height: 250px !important;
		object-fit: cover !important;
	}

	@media (min-width: 576px) {
		.card-columns {
			column-count: 1;
		}
	}

	@media (min-width: 768px) {
		.card-columns {
			column-count: 2;
		}
	}

	@media (min-width: 1200px) {
		.card-columns {
			column-count: 2;
		}
	}
</style>
@endpush

@section('content')
<div class="row">
	<div class="@if(boolval($userSettings['recipes_show_list_side_by_side']) || $embedded) col-12 col-md-6 @else col @endif d-print-none">
		<div class="title-related-links border-bottom mb-2 py-1">
			<h2 class="title">@yield('title')</h2>
			<div class="float-right @if($embedded) pr-5 @endif">
				<button class="btn btn-outline-dark d-md-none mt-2 order-1 order-md-3"
					type="button"
					data-toggle="collapse"
					data-target="#table-filter-row">
					<i class="fa-solid fa-filter"></i>
				</button>
				<button class="btn btn-outline-dark d-md-none mt-2 order-1 order-md-3"
					type="button"
					data-toggle="collapse"
					data-target="#related-links">
					<i class="fa-solid fa-ellipsis-v"></i>
				</button>
			</div>
			<div class="related-links collapse d-md-flex order-2 width-xs-sm-100"
				id="related-links">
				<a class="btn btn-primary responsive-button m-1 mt-md-0 mb-md-0 float-right"
					href="{{ $U('/recipe/new') }}">
					{{ $__t('Add') }}
				</a>
			</div>
		</div>

		<div class="row collapse d-md-flex"
			id="table-filter-row">
			<div class="col-12 col-md-5">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text"><i class="fa-solid fa-search"></i></span>
					</div>
					<input type="text"
						id="search"
						class="form-control"
						placeholder="{{ $__t('Search') }}">
				</div>
			</div>

			<div class="col-12 col-md-5">
				<div class="input-group">
					<div class="input-group-prepend">
						<span class="input-group-text"><i class="fa-solid fa-filter"></i>&nbsp;{{ $__t('Status') }}</span>
					</div>
					<select class="custom-control custom-select"
						id="status-filter">
						<option value="all">{{ $__t('All') }}</option>
						<option value="Xenoughinstock">{{ $__t('Enough in stock') }}</option>
						<option value="enoughinstockwithshoppinglist">{{ $__t('Not enough in stock, but already on the shopping list') }}</option>
						<option value="notenoughinstock">{{ $__t('Not enough in stock') }}</option>
					</select>
				</div>
			</div>

			<div class="col">
				<div class="float-right mt-1">
					<button id="clear-filter-button"
						class="btn btn-sm btn-outline-info"
						data-toggle="tooltip"
						title="{{ $__t('Clear filter') }}">
						<i class="fa-solid fa-filter-circle-xmark"></i>
					</button>
				</div>
			</div>
		</div>

		<ul class="nav nav-tabs grocy-tabs">
			<li class="nav-item">
				<a class="nav-link active"
					id="list-tab"
					data-toggle="tab"
					href="#list">{{ $__t('List') }}</a>
			</li>
			<li class="nav-item">
				<a class="nav-link"
					id="gallery-tab"
					data-toggle="tab"
					href="#gallery">{{ $__t('Gallery') }}</a>
			</li>
		</ul>

		<div class="tab-content grocy-tabs">
			<div class="tab-pane show active"
				id="list">
				<table id="recipes-table"
					class="table table-sm table-striped nowrap w-100">
					<thead>
						<tr>
							<th class="border-right"><a class="text-muted change-table-columns-visibility-button"
									data-toggle="tooltip"
									title="{{ $__t('Table options') }}"
									data-table-selector="#recipes-table"
									href="#"><i class="fa-solid fa-eye"></i></a>
							</th>
							<th>{{ $__t('Name') }}</th>
							<th class="allow-grouping">{{ $__t('Desired servings') }}</th>
							<th class="allow-grouping">
								{{ $__t('Due score') }}
								<i class="fa-solid fa-question-circle text-muted small"
									data-toggle="tooltip"
									data-trigger="hover click"
									title="{{ $__t('The higher this number is, the more ingredients currently in stock are due soon, overdue or already expired') }}"></i>
							</th>
							<th data-shadow-rowgroup-column="8"
								class="@if(!GROCY_FEATURE_FLAG_STOCK) d-none @endif allow-grouping">{{ $__t('Requirements fulfilled') }}</th>
							<th class="d-none">Hidden status for sorting of "Requirements fulfilled" column</th>
							<th class="d-none">Hidden status for filtering by status</th>
							<th class="d-none">Hidden recipe ingredient product names</th>
							<th class="d-none">Hidden status for grouping by status</th>

							@include('components.userfields_thead', array(
							'userfields' => $userfields
							))

						</tr>
					</thead>
					<tbody class="d-none">
						@foreach($recipes as $recipe)
						<tr id="recipe-row-{{ $recipe->id }}"
							data-recipe-id="{{ $recipe->id }}">
							<td class="fit-content border-right">
								<a class="btn btn-info btn-sm hide-when-embedded hide-on-fullscreen-card recipe-edit-button"
									href="{{ $U('/recipe/') }}{{ $recipe->id }}"
									data-toggle="tooltip"
									title="{{ $__t('Edit this item') }}">
									<i class="fa-solid fa-edit"></i>
								</a>
								<div class="dropdown d-inline-block">
									<button class="btn btn-sm btn-light text-secondary"
										type="button"
										data-toggle="dropdown">
										<i class="fa-solid fa-ellipsis-v"></i>
									</button>
									<div class="table-inline-menu dropdown-menu dropdown-menu-right hide-on-fullscreen-card hide-when-embedded">
										<a class="dropdown-item add-to-mealplan-button"
											type="button"
											href="#"
											data-recipe-id="{{ $recipe->id }}">
											<span class="dropdown-item-text">{{ $__t('Add to meal plan') }}</span>
										</a>
										<a class="dropdown-item recipe-delete"
											type="button"
											href="#"
											data-recipe-id="{{ $recipe->id }}"
											data-recipe-name="{{ $recipe->name }}">
											<span class="dropdown-item-text">{{ $__t('Delete this item') }}</span>
										</a>
										<a class="dropdown-item recipe-copy"
											type="button"
											href="#"
											data-recipe-id="{{ $recipe->id }}">
											<span class="dropdown-item-text">{{ $__t('Copy recipe') }}</span>
										</a>
										<div class="dropdown-divider"></div>
										<a class="dropdown-item"
											type="button"
											href="{{ $U('/recipe/' . $recipe->id . '/grocycode?download=true') }}">
											<span class="dropdown-item-text">{!! str_replace('Grocycode', '<span class="ls-n1">Grocycode</span>', $__t('Download %s Grocycode', $__t('Recipe'))) !!}</span>
										</a>
										@if(GROCY_FEATURE_FLAG_LABEL_PRINTER)
										<a class="dropdown-item recipe-grocycode-label-print"
											data-recipe-id="{{ $recipe->id }}"
											type="button"
											href="#">
											<span class="dropdown-item-text">{!! str_replace('Grocycode', '<span class="ls-n1">Grocycode</span>', $__t('Print %s Grocycode on label printer', $__t('Recipe'))) !!}</span>
										</a>
										@endif
									</div>
								</div>
							</td>
							<td>
								{{ $recipe->name }}
							</td>
							<td>
								{{ $recipe->desired_servings }}
							</td>
							<td>
								{{ FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->due_score }}
							</td>
							<td class="@if(!GROCY_FEATURE_FLAG_STOCK) d-none @endif">
								@if(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled == 1)<i class="fa-solid fa-check text-success"></i>@elseif(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled_with_shopping_list == 1)<i class="fa-solid fa-exclamation text-warning"></i>@else<i class="fa-solid fa-times text-danger"></i>@endif
								<span class="timeago-contextual">@if(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled == 1){{ $__t('Enough in stock') }}@elseif(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled_with_shopping_list == 1){{ $__n(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->missing_products_count, 'Not enough in stock, %s ingredient missing but already on the shopping list', 'Not enough in stock, %s ingredients missing but already on the shopping list') }}@else{{ $__n(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->missing_products_count, 'Not enough in stock, %s ingredient missing', 'Not enough in stock, %s ingredients missing') }}@endif</span>
							</td>
							<td class="d-none">
								{{ FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->missing_products_count }}
							</td>
							<td class="d-none">
								@if(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled == 1) Xenoughinstock @elseif(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled_with_shopping_list == 1) enoughinstockwithshoppinglist @else notenoughinstock @endif
							</td>
							<td class="d-none">
								{{ FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->product_names_comma_separated }}
							</td>
							<td class="d-none">
								@if(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled == 1) {{ $__t('Enough in stock') }} @elseif(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled_with_shopping_list == 1) {{ $__t('Not enough in stock, but already on the shopping list') }} @else {{ $__t('Not enough in stock') }} @endif
							</td>

							@include('components.userfields_tbody', array(
							'userfields' => $userfields,
							'userfieldValues' => FindAllObjectsInArrayByPropertyValue($userfieldValues, 'object_id', $recipe->id)
							))

						</tr>
						@endforeach
					</tbody>
				</table>
			</div>

			<div class="tab-pane show"
				id="gallery">
				<div class="card-columns no-gutters mt-1">
					@foreach($recipes as $recipe)
					<div class="cursor-link recipe-gallery-item @if(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled == 1) recipe-enoughinstock @elseif(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled_with_shopping_list == 1) recipe-enoughinstockwithshoppinglist @else recipe-notenoughinstock @endif"
						data-recipe-id="{{ $recipe->id }}"
						href="#">
						<div id="RecipeGalleryCard-{{ $recipe->id }}"
							class="card recipe-card">
							@if(!empty($recipe->picture_file_name))
							<img src="{{ $U('/api/files/recipepictures/' . base64_encode($recipe->picture_file_name) . '?force_serve_as=picture&best_fit_width=400') }}"
								class="card-img-top"
								loading="lazy">
							@endif
							<div class="card-body text-center">
								<h5 class="card-title mb-1">{{ $recipe->name }}</h5>
								<span class="card-title-search d-none">
									{{ $recipe->name }}
									{{ FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->product_names_comma_separated }}
								</span>
								<p class="card-text">
									@if(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled == 1)<i class="fa-solid fa-check text-success"></i>@elseif(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled_with_shopping_list == 1)<i class="fa-solid fa-exclamation text-warning"></i>@else<i class="fa-solid fa-times text-danger"></i>@endif
									<span class="timeago-contextual">@if(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled == 1){{ $__t('Enough in stock') }}@elseif(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled_with_shopping_list == 1){{ $__n(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->missing_products_count, 'Not enough in stock, %s ingredient missing but already on the shopping list', 'Not enough in stock, %s ingredients missing but already on the shopping list') }}@else{{ $__n(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->missing_products_count, 'Not enough in stock, %s ingredient missing', 'Not enough in stock, %s ingredients missing') }}@endif</span>
								</p>
								<p class="card-text mt-2">
									<a class="btn btn-xs btn-outline-danger hide-when-embedded hide-on-fullscreen-card recipe-delete"
										href="#"
										data-recipe-id="{{ $recipe->id }}"
										data-recipe-name="{{ $recipe->name }}"
										data-toggle="tooltip"
										title="{{ $__t('Delete this item') }}">
										<i class="fa-solid fa-trash"></i>
									</a>
									<a class="btn btn-outline-info btn-xs hide-when-embedded hide-on-fullscreen-card recipe-edit-button"
										href="{{ $U('/recipe/') }}{{ $recipe->id }}"
										data-toggle="tooltip"
										title="{{ $__t('Edit this item') }}">
										<i class="fa-solid fa-edit"></i>
									</a>
								</p>
							</div>
						</div>
					</div>
					@endforeach
				</div>
			</div>
		</div>

		@if($pagination['totalPages'] > 1)
		@php
		$paginationQueryParams = $queryParams;
		unset($paginationQueryParams['page']);
		unset($paginationQueryParams['page_size']);
		$paginationQueryTail = http_build_query($paginationQueryParams);
		if (!empty($paginationQueryTail))
		{
			$paginationQueryTail = '&' . $paginationQueryTail;
		}
		@endphp
		<nav aria-label="{{ $__t('Recipes pagination') }}"
			class="mt-3">
			<ul class="pagination pagination-sm mb-0">
				<li class="page-item @if(!$pagination['hasPreviousPage']) disabled @endif">
					<a class="page-link"
						href="{{ $U('/recipes?page=' . $pagination['previousPage'] . '&page_size=' . $pagination['pageSize'] . $paginationQueryTail) }}">{{ $__t('Previous') }}</a>
				</li>
				<li class="page-item disabled">
					<span class="page-link">{{ $__t('Page %1$s of %2$s', $pagination['page'], $pagination['totalPages']) }}</span>
				</li>
				<li class="page-item @if(!$pagination['hasNextPage']) disabled @endif">
					<a class="page-link"
						href="{{ $U('/recipes?page=' . $pagination['nextPage'] . '&page_size=' . $pagination['pageSize'] . $paginationQueryTail) }}">{{ $__t('Next') }}</a>
				</li>
			</ul>
		</nav>
		@endif
	</div>

	@if(boolval($userSettings['recipes_show_list_side_by_side']) || $embedded)
	<div class="col-12 col-md-6 print-view">
		<div id="selectedRecipeDetailsContainer"
			data-selected-recipe-id="{{ $selectedRecipeId ?? '' }}">
			<div class="alert alert-info mb-0">{{ $__t('Select a recipe to load details') }}</div>
		</div>
	</div>
	@endif
</div>

<div class="modal fade"
	id="add-to-mealplan-modal"
	tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title w-100">
					<span>{{ $__t('Add meal plan entry') }}</span>
					<span class="text-muted float-right">{{ $__t('Recipe') }}</span>
				</h4>
			</div>
			<div class="modal-body">
				<form id="add-to-mealplan-form"
					novalidate>

					@include('components.datetimepicker', array(
					'id' => 'day',
					'label' => 'Day',
					'format' => 'YYYY-MM-DD',
					'initWithNow' => false,
					'limitEndToNow' => false,
					'limitStartToNow' => false,
					'isRequired' => true,
					'additionalCssClasses' => 'date-only-datetimepicker',
					'invalidFeedback' => $__t('A date is required')
					))

					@include('components.recipepicker', array(
					'recipes' => $recipesForPicker,
					'isRequired' => true,
					'nextInputSelector' => '#recipe_servings'
					))

					@include('components.numberpicker', array(
					'id' => 'recipe_servings',
					'label' => 'Servings',
					'min' => $DEFAULT_MIN_AMOUNT,
					'decimals' => $userSettings['stock_decimal_places_amounts'],
					'value' => '1',
					'additionalCssClasses' => 'locale-number-input locale-number-quantity-amount'
					))

					<div class="form-group">
						<label for="section_id">{{ $__t('Section') }}</label>
						<select class="custom-control custom-select"
							id="section_id"
							name="section_id"
							required>
							@foreach($mealplanSections as $mealplanSection)
							<option value="{{ $mealplanSection->id }}">{{ $mealplanSection->name }}</option>
							@endforeach
						</select>
					</div>

					<input type="hidden"
						name="type"
						value="recipe">
				</form>
			</div>
			<div class="modal-footer">
				<button type="button"
					class="btn btn-secondary"
					data-dismiss="modal">{{ $__t('Cancel') }}</button>
				<button id="save-add-to-mealplan-button"
					class="btn btn-success">{{ $__t('Save') }}</button>
			</div>
		</div>
	</div>
</div>

@include('components.productcard', [
'asModal' => true
])
@stop
