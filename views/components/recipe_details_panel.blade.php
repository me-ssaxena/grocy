@if($selectedRecipe !== null)
@php
$allRecipes = $selectedRecipeSubRecipes;
array_unshift($allRecipes, $selectedRecipe);
@endphp
<div id="selectedRecipeCard"
	class="card grocy-card">
	@if(count($allRecipes) > 1)
	<div class="card-header card-header-fullscreen mb-1 pt-0 d-print-none">
		<ul class="nav nav-tabs grocy-tabs card-header-tabs">
			@foreach($allRecipes as $index=>$recipe)
			<li class="nav-item">
				<a class="nav-link @if($index == 0) active @endif"
					data-toggle="tab"
					href="#recipe-{{ $index + 1 }}-{{ $selectedRecipe->id }}">{{ $recipe->name }}</a>
			</li>
			@endforeach
		</ul>
	</div>
	@endif

	<div class="tab-content grocy-tabs print break">
		@foreach($allRecipes as $index=>$recipe)
		<div class="tab-pane @if($index == 0) active @endif"
			id="recipe-{{ $index + 1 }}-{{ $selectedRecipe->id }}"
			role="tabpanel">
			@if(!empty($recipe->picture_file_name))
			<img class="card-img-top"
				src="{{ $U('/api/files/recipepictures/' . base64_encode($recipe->picture_file_name) . '?force_serve_as=picture') }}"
				loading="lazy">
			@endif
			<div class="card-body">
				<div class="shadow p-4 mb-5 bg-white rounded mt-n5 d-print-none @if(empty($recipe->picture_file_name)) d-none @endif">
					<div class="d-flex justify-content-between align-items-center">
						<h3 class="card-title mb-0">{{ $recipe->name }}</h3>
						<div class="card-icons d-flex flex-wrap justify-content-end flex-shrink-1">
							<a class="btn @if(!GROCY_FEATURE_FLAG_STOCK) d-none @endif recipe-consume"
								href="#"
								data-toggle="tooltip"
								title="{{ $__t('Consume all ingredients needed by this recipe') }}"
								data-recipe-id="{{ $recipe->id }}"
								data-recipe-name="{{ $recipe->name }}">
								<i class="fa-solid fa-utensils"></i>
							</a>
							<a class="btn @if(!GROCY_FEATURE_FLAG_SHOPPINGLIST) d-none @endif recipe-shopping-list @if(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled_with_shopping_list == 1) disabled @endif"
								href="#"
								data-toggle="tooltip"
								title="{{ $__t('Put missing products on shopping list') }}"
								data-recipe-id="{{ $recipe->id }}"
								data-recipe-name="{{ $recipe->name }}">
								<i class="fa-solid fa-cart-plus"></i>
							</a>
							<a class="btn recipe-fullscreen hide-when-embedded"
								id="selectedRecipeToggleFullscreenButton"
								href="#"
								data-toggle="tooltip"
								title="{{ $__t('Expand to fullscreen') }}">
								<i class="fa-solid fa-expand-arrows-alt"></i>
							</a>
							<a class="btn recipe-print"
								href="#"
								data-toggle="tooltip"
								title="{{ $__t('Print') }}">
								<i class="fa-solid fa-print"></i>
							</a>
						</div>
					</div>
				</div>

				<div class="mb-4 @if(!empty($recipe->picture_file_name)) d-none @else d-flex @endif d-print-block justify-content-between align-items-center">
					<h1 class="card-title mb-0">{{ $recipe->name }}</h1>
					<div class="card-icons d-flex flex-wrap justify-content-end flex-shrink-1 d-print-none">
						<a class="btn recipe-consume"
							href="#"
							data-toggle="tooltip"
							title="{{ $__t('Consume all ingredients needed by this recipe') }}"
							data-recipe-id="{{ $recipe->id }}"
							data-recipe-name="{{ $recipe->name }}">
							<i class="fa-solid fa-utensils"></i>
						</a>
						<a class="btn recipe-shopping-list @if(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->need_fulfilled_with_shopping_list == 1) disabled @endif"
							href="#"
							data-toggle="tooltip"
							title="{{ $__t('Put missing products on shopping list') }}"
							data-recipe-id="{{ $recipe->id }}"
							data-recipe-name="{{ $recipe->name }}">
							<i class="fa-solid fa-cart-plus"></i>
						</a>
						<a class=" btn recipe-fullscreen hide-when-embedded"
							href="#"
							data-toggle="tooltip"
							title="{{ $__t('Expand to fullscreen') }}">
							<i class="fa-solid fa-expand-arrows-alt"></i>
						</a>
						<a class="btn recipe-print PrintRecipe"
							href="#"
							data-toggle="tooltip"
							title="{{ $__t('Print') }}">
							<i class="fa-solid fa-print"></i>
						</a>
					</div>
				</div>

				@php
				$calories = FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->calories;
				$costs = FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->costs;
				@endphp

				<div class="row ml-1">
					@if(!empty($calories) && $calories > 0)
					<div class="col-4">
						<label>{{ GROCY_ENERGY_UNIT }}</label>&nbsp;
						<i class="fa-solid fa-question-circle text-muted d-print-none"
							data-toggle="tooltip"
							data-trigger="hover click"
							title="{{ $__t('per serving') }}"></i>
						<h3 class="locale-number locale-number-generic pt-0">{{ $calories }}</h3>
					</div>
					@endif
					@if(GROCY_FEATURE_FLAG_STOCK_PRICE_TRACKING)
					<div class="col-4">
						<label>{{ $__t('Costs') }}&nbsp;
							<i class="fa-solid fa-question-circle text-muted d-print-none"
								data-toggle="tooltip"
								data-trigger="hover click"
								title="{{ $__t('Based on the prices of the default consume rule (Opened first, then first due first, then first in first out) for in stock ingredients and on the last price for missing ones') }}"></i>
						</label>
						<h3>
							<span class="locale-number locale-number-currency pt-0">{{ $costs }}</span>
							@if(FindObjectInArrayByPropertyValue($recipesResolved, 'recipe_id', $recipe->id)->prices_incomplete)
							<i class="small fa-solid fa-exclamation text-danger"
								data-toggle="tooltip"
								data-trigger="hover click"
								title="{{ $__t('No price information is available for at least one ingredient') }}"></i>
							@endif
						</h3>
					</div>
					@endif

					@if($index == 0)
					<div class="col-4 d-print-none">
						@include('components.numberpicker', array(
						'id' => 'servings-scale',
						'label' => 'Desired servings',
						'min' => $DEFAULT_MIN_AMOUNT,
						'decimals' => $userSettings['stock_decimal_places_amounts'],
						'value' => $recipe->desired_servings,
						'additionalAttributes' => 'data-recipe-id="' . $recipe->id . '"',
						'additionalCssClasses' => 'locale-number-input locale-number-quantity-amount'
						))
					</div>
					@endif
				</div>

				@php
				$recipePositionsFiltered = FindAllObjectsInArrayByPropertyValue($allRecipePositions[$recipe->id], 'recipe_id', $recipe->id);
				@endphp

				<ul class="nav nav-tabs grocy-tabs mb-3 d-print-none hide-on-fullscreen-card"
					role="tablist">
					@if(count($recipePositionsFiltered) > 0)
					<li class="nav-item">
						<a class="nav-link active"
							data-toggle="tab"
							href="#ingredients-{{ $index }}-{{ $selectedRecipe->id }}"
							role="tab">{{ $__t('Ingredients') }}</a>
					</li>
					@endif
					@if(!empty($recipe->description))
					<li class="nav-item">
						<a class="nav-link @if(count($recipePositionsFiltered) == 0) active @endif"
							data-toggle="tab"
							href="#prep-{{ $index }}-{{ $selectedRecipe->id }}"
							role="tab">{{ $__t('Preparation') }}</a>
					</li>
					@endif
				</ul>

				<div class="tab-content grocy-tabs p-2 print recipe-content-container">
					@if(count($recipePositionsFiltered) > 0)
					<div class="tab-pane active ingredients"
						id="ingredients-{{ $index }}-{{ $selectedRecipe->id }}"
						role="tabpanel">
						<div class="mb-2 d-none d-print-block recipe-headline">
							<h3 class="mb-0">{{ $__t('Ingredients') }}</h3>
						</div>
						<ul class="list-group list-group-flush mb-5">
							@php
							$lastIngredientGroup = 'undefined';
							$lastProductGroup = 'undefined';
							$hasIngredientGroups = false;
							$hasProductGroups = false;
							@endphp
							@foreach($recipePositionsFiltered as $selectedRecipePosition)
							@if($lastIngredientGroup != $selectedRecipePosition->ingredient_group && !empty($selectedRecipePosition->ingredient_group))
							@php $hasIngredientGroups = true; @endphp
							<h5 class="mb-2 mt-2 ml-1"><strong>{{ $selectedRecipePosition->ingredient_group }}</strong></h5>
							@endif
							@if(boolval($userSettings['recipe_ingredients_group_by_product_group']) && $lastProductGroup != $selectedRecipePosition->product_group && !empty($selectedRecipePosition->product_group))
							@php $hasProductGroups = true; @endphp
							<h6 class="mb-2 mt-2 @if($hasIngredientGroups) ml-3 @else ml-1 @endif"><strong>{{ $selectedRecipePosition->product_group }}</strong></h6>
							@endif
							<li class="list-group-item px-0 @if($hasIngredientGroups && $hasProductGroups) ml-4 @elseif($hasIngredientGroups || $hasProductGroups) ml-2 @else ml-0 @endif">
								@if($selectedRecipePosition->product_active == 0)
								<div class="small text-muted font-italic">{{ $__t('Disabled') }}</div>
								@endif
								@if($userSettings['recipes_show_ingredient_checkbox'])
								<a class="btn btn-light btn-sm ingredient-done-button"
									href="#"
									data-toggle="tooltip"
									data-placement="right"
									title="{{ $__t('Mark this item as done') }}">
									<i class="fa-solid fa-check-circle text-primary"></i>
								</a>
								@endif
								@php
								$product = FindObjectInArrayByPropertyValue($products, 'id', $selectedRecipePosition->product_id);
								$productQuConversions = FindAllObjectsInArrayByPropertyValue($quantityUnitConversionsResolved, 'product_id', $product->id);
								$productQuConversions = FindAllObjectsInArrayByPropertyValue($productQuConversions, 'from_qu_id', $product->qu_id_stock);
								$productQuConversion = FindObjectInArrayByPropertyValue($productQuConversions, 'to_qu_id', $selectedRecipePosition->qu_id);
								if ($productQuConversion && $selectedRecipePosition->only_check_single_unit_in_stock == 0)
								{
								$selectedRecipePosition->recipe_amount = $selectedRecipePosition->recipe_amount * $productQuConversion->factor;
								}
								@endphp
								<span class="productcard-trigger cursor-link @if($selectedRecipePosition->due_score == 20) text-danger @elseif($selectedRecipePosition->due_score == 10) text-secondary @elseif($selectedRecipePosition->due_score == 1) text-warning @endif"
									data-product-id="{{ $selectedRecipePosition->product_id }}">
									@if(!empty($selectedRecipePosition->recipe_variable_amount))
									{{ $selectedRecipePosition->recipe_variable_amount }}
									@else
									<span class="locale-number locale-number-quantity-amount">@if($selectedRecipePosition->recipe_amount == round($selectedRecipePosition->recipe_amount, 2)){{ round($selectedRecipePosition->recipe_amount, 2) }}@else{{ $selectedRecipePosition->recipe_amount }}@endif</span>
									{{ $__n($selectedRecipePosition->recipe_amount, FindObjectInArrayByPropertyValue($quantityUnits, 'id', $selectedRecipePosition->qu_id)->name, FindObjectInArrayByPropertyValue($quantityUnits, 'id', $selectedRecipePosition->qu_id)->name_plural) }}
									@endif
									{{ FindObjectInArrayByPropertyValue($products, 'id', $selectedRecipePosition->product_id)->name }}
								</span>
								@if(GROCY_FEATURE_FLAG_STOCK)
								<span class="
										d-print-none">
									@if(FindObjectInArrayByPropertyValue($recipePositionsResolved, 'recipe_pos_id', $selectedRecipePosition->id)->need_fulfilled == 1)<i class="fa-solid fa-check text-success"></i>@elseif(FindObjectInArrayByPropertyValue($recipePositionsResolved, 'recipe_pos_id', $selectedRecipePosition->id)->need_fulfilled_with_shopping_list == 1)<i class="fa-solid fa-exclamation text-warning"></i>@else<i class="fa-solid fa-times text-danger"></i>@endif
									<span class="timeago-contextual">@if(FindObjectInArrayByPropertyValue($recipePositionsResolved, 'recipe_pos_id', $selectedRecipePosition->id)->need_fulfilled == 1) {{ $__t('Enough in stock') }} (<span class="locale-number locale-number-quantity-amount">{{ $selectedRecipePosition->stock_amount }}</span> {{ $__n($selectedRecipePosition->stock_amount, FindObjectInArrayByPropertyValue($quantityUnits, 'id', $product->qu_id_stock)->name, FindObjectInArrayByPropertyValue($quantityUnits, 'id', $product->qu_id_stock)->name_plural) }}) @else {{ $__t('Not enough in stock, %1$s missing, %2$s already on shopping list', round($selectedRecipePosition->missing_amount, 2), round($selectedRecipePosition->amount_on_shopping_list, 2)) }} @endif</span>
								</span>
								@endif
								@if($selectedRecipePosition->product_id != $selectedRecipePosition->product_id_effective)
								<br class="d-print-none">
								<span class="productcard-trigger cursor-link text-muted d-print-none"
									data-product-id="{{ $selectedRecipePosition->product_id_effective }}"
									data-toggle="tooltip"
									data-trigger="hover click"
									title="{{ $__t('The parent product %1$s is currently not in stock, %2$s is the current next sub product based on the default consume rule (Opened first, then first due first, then first in first out)', FindObjectInArrayByPropertyValue($products, 'id', $selectedRecipePosition->product_id)->name, FindObjectInArrayByPropertyValue($products, 'id', $selectedRecipePosition->product_id_effective)->name) }}">
									<i class="fa-solid fa-exchange-alt"></i> {{ FindObjectInArrayByPropertyValue($products, 'id', $selectedRecipePosition->product_id_effective)->name }}
								</span>
								@endif
								@if(GROCY_FEATURE_FLAG_STOCK_PRICE_TRACKING) <span class="float-right font-italic ml-2 locale-number locale-number-currency">{{ $selectedRecipePosition->costs }}</span> @endif
								<span class="float-right font-italic"><span class="locale-number locale-number-generic">{{ $selectedRecipePosition->calories }}</span> {{ $__t('Calories') }}</span>
								@if(!empty($selectedRecipePosition->recipe_variable_amount))
								<div class="small text-muted font-italic">{{ $__t('Variable amount') }}</div>
								@endif

								@if(!empty($selectedRecipePosition->note))
								<div class="text-muted">{!! nl2br($selectedRecipePosition->note ?? '') !!}</div>
								@endif
							</li>
							@php $lastProductGroup = $selectedRecipePosition->product_group; @endphp
							@php $lastIngredientGroup = $selectedRecipePosition->ingredient_group; @endphp
							@endforeach
						</ul>
					</div>
					@endif
					<div class="tab-pane @if(count($recipePositionsFiltered) == 0) active @endif preparation"
						id="prep-{{ $index }}-{{ $selectedRecipe->id }}"
						role="tabpanel">
						<div class="mb-2 d-none d-print-block recipe-headline">
							<h3 class="mb-0">{{ $__t('Preparation') }}</h3>
						</div>
						@if(!empty($recipe->description))
						{!! $recipe->description !!}
						@endif
					</div>
				</div>
			</div>
		</div>
		@endforeach

		<div id="missing-recipe-pos-list"
			class="list-group d-none mt-3">
			@foreach($recipePositionsResolved as $recipePos)
			@if(in_array($recipePos->recipe_id, $includedRecipeIdsAbsolute) && $recipePos->missing_amount > 0)
			<a href="#"
				class="list-group-item list-group-item-action list-group-item-primary missing-recipe-pos-select-button">
				<div class="form-check form-check-inline">
					<input class="form-check-input missing-recipe-pos-product-checkbox"
						type="checkbox"
						data-product-id="{{ $recipePos->product_id }}"
						checked>
				</div>
				{{ FindObjectInArrayByPropertyValue($products, 'id', $recipePos->product_id)->name }}
			</a>
			@endif
			@endforeach
		</div>
	</div>
</div>
@endif
