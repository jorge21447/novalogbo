<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\City;
use App\Models\Cost;
use App\Models\Country;
use App\Models\Product;
use App\Models\Service;
use App\Models\Customer;
use App\Models\Incoterm;
use App\Models\Continent;
use App\Models\Quotation;
use App\Models\CostDetail;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use App\Models\productDetail;
use PhpOffice\PhpWord\PhpWord;
use App\Models\QuotationService;
use PhpOffice\PhpWord\IOFactory;
use Illuminate\Support\Facades\DB;
use App\Models\QuantityDescription;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpWord\SimpleType\JcTable;
use PhpOffice\PhpWord\SimpleType\TblWidth;

class QuotationController extends Controller
{
    //

    public function index()
    {
        if (Auth::user()->role_id === 1) {
            $quotations = Quotation::with(['customer', 'user'])
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            $quotations = Quotation::with(['customer'])
                ->where('users_id', Auth::id())
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return view('quotations.index', compact('quotations'));
    }


    public function create()
    {
        $incoterms = Incoterm::where('is_active', 1)->get();
        $services = Service::where('is_active', 1)->get();
        $countries = Country::whereNull('deleted_at')->get();
        $cities = City::whereNull('deleted_at')->get();
        $costs = Cost::where('is_active', 1)->get();
        $exchangeRates = ExchangeRate::where('active', 1)->get();
        $customers = Customer::where('active', 1)->get();
        $QuantityDescriptions = QuantityDescription::where('is_active', 1)->get();

        return view('quotations.create', compact(
            'incoterms',
            'services',
            'countries',
            'cities',
            'costs',
            'exchangeRates',
            'customers',
            'QuantityDescriptions'
        ));
    }

    public function searchCustomer(Request $request)
    {
        $search = $request->get('search');

        $customers = Customer::where('NIT', 'LIKE', "%{$search}%")
            ->orWhere('name', 'LIKE', "%{$search}%")
            ->where('active', true)
            ->select('NIT as id', 'name', 'email')
            ->limit(10)
            ->get();

        return response()->json($customers);
    }

    public function searchQuantityDescription(Request $request)
    {
        $search = $request->get('search');

        $quantityDescription = QuantityDescription::where('name', 'LIKE', "%{$search}%")
            ->where('is_active', true)
            ->get();

        return response()->json($quantityDescription);
    }

    public function searchLocation(Request $request)
    {
        $request->validate([
            'searchTerm' => 'required|string|max:255',
        ]);

        $searchTerm = trim(strtolower($request->input('searchTerm')));

        if (strlen($searchTerm) < 2) {
            return response()->json(['success' => false]);
        }

        try {
            $searchPattern = "%{$searchTerm}%";

            // 1. Países que coinciden (con todas sus ciudades)
            $matchingCountries = Country::whereRaw('LOWER(name) LIKE ?', [$searchPattern])
                ->with(['cities' => function ($query) {
                    $query->select('id', 'name', 'country_id');
                }])
                ->get(['id', 'name']);

            // 2. Ciudades que coinciden (con su país)
            $matchingCities = City::whereRaw('LOWER(name) LIKE ?', [$searchPattern])
                ->with(['country' => function ($query) {
                    $query->select('id', 'name');
                }])
                ->get(['id', 'name', 'country_id']);

            // Procesar resultados
            $results = $matchingCountries->map(function ($country) use ($searchTerm) {
                return [
                    'id' => $country->id,
                    'name' => $country->name,
                    'type' => 'country',
                    'match_type' => 'country',
                    'cities' => $country->cities->map(function ($city) use ($country, $searchTerm) {
                        return [
                            'id' => $city->id,
                            'name' => $city->name,
                            'type' => 'city',
                            'match_type' => str_contains(strtolower($city->name), $searchTerm) ? 'city' : null,
                            'country_id' => $country->id,
                            'country_name' => $country->name
                        ];
                    })->toArray()
                ];
            })->toArray();

            // Agregar ciudades coincidentes cuyos países no coincidieron
            $processedCountryIds = collect($results)->pluck('id')->toArray();
            $processedCityIds = collect($results)->pluck('cities.*.id')->flatten()->toArray();

            foreach ($matchingCities as $city) {
                if (!in_array($city->id, $processedCityIds)) {
                    $country = $city->country;

                    if (in_array($country->id, $processedCountryIds)) {
                        $countryIndex = array_search($country->id, array_column($results, 'id'));
                        $results[$countryIndex]['cities'][] = [
                            'id' => $city->id,
                            'name' => $city->name,
                            'type' => 'city',
                            'match_type' => 'city',
                            'country_id' => $country->id,
                            'country_name' => $country->name
                        ];
                    } else {
                        $results[] = [
                            'id' => $country->id,
                            'name' => $country->name,
                            'type' => 'country',
                            'match_type' => null,
                            'cities' => [[
                                'id' => $city->id,
                                'name' => $city->name,
                                'type' => 'city',
                                'match_type' => 'city',
                                'country_id' => $country->id,
                                'country_name' => $country->name
                            ]]
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => config('app.debug') ? $e->getMessage() : null
            ]);
        }
    }

    public function storeCustomer(Request $request)
    {
        try {
            // Validar los datos de entrada
            $validator = Validator::make($request->all(), [
                'NIT' => 'required|integer|unique:customers',
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:customers,email',
                'phone' => 'nullable|string|max:20',
                'cellphone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:255',
                'department' => 'nullable|string|max:100',
                'role_id' => 'required|exists:roles,id',
            ], [
                'NIT.required' => 'El NIT\CI es obligatorio.',
                'NIT.integer' => 'El NIT\CI debe ser un número entero.',
                'NIT.unique' => 'Este NIT\CI ya está en uso.',
                'name.required' => 'La razón social es obligatoria.',
                'email.required' => 'El correo electrónico es obligatorio.',
                'email.email' => 'El correo electrónico debe ser una dirección válida.',
                'email.unique' => 'Este correo electrónico ya está en uso.',
                'phone.nullable' => 'El teléfono es opcional.',
                'cellphone.nullable' => 'El celular es opcional.',
                'address.nullable' => 'La dirección es opcional.',
                'department.nullable' => 'El departamento o lugar de residencia es opcional.',
                'role_id.required' => 'El rol es obligatorio.',
                'role_id.exists' => 'El rol seleccionado no es válido.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors(),
                    'customer' => null
                ], 422);
            }

            // Crear un nuevo cliente
            $customer = Customer::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Cliente creado exitosamente',
                'customer' => $customer,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el cliente: ' . $e->getMessage(),
                'customer' => null
            ], 500);
        }
    }

    public function storeQuantityDescripcion(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'is_active' => 'required|boolean',
            ], [
                'name.required' => 'El nombre es obligatorio.',
                'name.string' => 'El nombre debe ser una cadena de texto.',
                'name.max' => 'El nombre no puede exceder los 255 caracteres.',
                'is_active.required' => 'El estado es obligatorio.',
                'is_active.boolean' => 'El estado debe ser verdadero o falso.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors(),
                    'quantityDescription' => null
                ], 422);
            }

            $quantityDescription = QuantityDescription::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Cliente creado exitosamente',
                'quantityDescription' => $quantityDescription,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el cliente: ' . $e->getMessage(),
                'quantityDescription' => null
            ], 500);
        }
    }

    public function store(Request $request)
    {
        // dd($request);
        $validatedData = $request->validate(
            [
                'reference_customer' => 'nullable|string',
                'reference_number' => 'nullable|string',
                'currency' => 'required|string|max:3',
                'exchange_rate' => 'required|numeric',
                'NIT' => 'required|exists:customers,NIT',
                'products' => 'nullable|array',
                'products.*.origin_id' => 'required_with:products',
                'products.*.destination_id' => 'required_with:products',
                'products.*.incoterm_id' => 'required_with:products',
                'products.*.quantity' => 'required_with:products|string',
                'products.*.quantity_description_id' => 'required_with:products',
                'products.*.weight' => 'nullable|numeric',
                'products.*.volume' => 'nullable|numeric',
                'products.*.volume_unit' => 'nullable|string|max:10',
                'products.*.description' => 'nullable|string',
                'costs' => 'nullable|array',
                'services' => 'nullable|array',
            ],
            [
                'currency.required' => 'La moneda es obligatoria.',
                'currency.string' => 'La moneda debe ser una cadena de texto.',
                'currency.max' => 'La moneda no puede exceder los 3 caracteres.',
                'exchange_rate.required' => 'El tipo de cambio es obligatorio.',
                'exchange_rate.numeric' => 'El tipo de cambio debe ser un número.',
                'NIT.required' => 'El NIT es obligatorio.',
                'NIT.exists' => 'El NIT no existe en la base de datos.',
                'products.array' => 'Los productos deben ser un arreglo.',
                'products.*.origin_id.required_with' => 'El origen es obligatorio.',
                'products.*.destination_id.required_with' => 'El destino es obligatorio.',
                'products.*.incoterm_id.required_with' => 'El incoterm es obligatorio.',
                'products.*.quantity.required_with' => 'La cantidad es obligatoria.',
                'products.*.quantity.string' => 'La cantidad debe ser una cadena de texto.',
                'products.*.quantity_description_id.required_with' => 'La descripción de la cantidad es obligatoria.',
                'products.*.weight.numeric' => 'El peso debe ser un número.',
                'products.*.volume.numeric' => 'El volumen debe ser un número.',
                'products.*.volume_unit.string' => 'La unidad de volumen debe ser una cadena de texto.',
                'products.*.volume_unit.max' => 'La unidad de volumen no puede exceder los 10 caracteres.',
                'products.*.description.string' => 'La descripción debe ser una cadena de texto.',
            ]
        );

        DB::beginTransaction();

        try {
            $quotation = new Quotation();
            $quotation->delivery_date = Carbon::now();
            $currentYear = Carbon::now()->year;
            $count = Quotation::whereYear('created_at', $currentYear)->count();
            $nextNumber = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
            $quotation->reference_number = "{$nextNumber}/" . substr($currentYear, -2);
            $quotation->reference_customer = $validatedData['reference_customer'] ?? '';
            $quotation->currency = $validatedData['currency'];
            $quotation->exchange_rate = $validatedData['exchange_rate'];
            $quotation->amount = 0;
            $quotation->customer_nit = $validatedData['NIT'];
            $quotation->users_id = Auth::id();
            $quotation->status = 'pending';
            $quotation->save();

            $totalAmount = 0;

            if ($request->has('products')) {
                foreach ($request->products as $product) {
                    $productDetail = new Product();
                    $productDetail->quotation_id = $quotation->id;
                    $productDetail->name = $product['name'] ?? '';
                    $productDetail->origin_id = $product['origin_id'];
                    $productDetail->destination_id = $product['destination_id'];
                    $productDetail->incoterm_id = $product['incoterm_id'];
                    $productDetail->quantity = $product['quantity'];
                    $productDetail->quantity_description_id = $product['quantity_description_id'];
                    $productDetail->weight = $product['weight'];
                    $productDetail->volume = $product['volume'];
                    $productDetail->volume_unit = $product['volume_unit'];
                    $productDetail->description = $product['description'] ?? '';
                    $productDetail->save();
                }
            }

            // Process cost details for this quotation
            if ($request->has('costs')) {
                foreach ($request->costs as $cost) {
                    if (isset($cost['enabled']) && $cost['enabled']) {
                        $costDetail = new CostDetail();
                        $costDetail->quotation_id = $quotation->id;
                        $costDetail->cost_id = $cost['cost_id'];
                        $costDetail->concept = $cost['concept'] ?? '';
                        $costDetail->amount = $cost['amount'];
                        $costDetail->currency = $quotation->currency;
                        $costDetail->save();

                        $totalAmount += $cost['amount'];
                    }
                }
            }

            // Process services
            if ($request->has('services')) {
                foreach ($request->services as $key => $service) {
                    if($service !== 'none') {
                        $quotationService = new QuotationService();
                        $quotationService->quotation_id = $quotation->id;
                        $quotationService->service_id = $key;
                        $quotationService->included = $service == 'include' ? true : false;
                        $quotationService->save();
                    }
                }
            }

            // Update total amount
            $quotation->amount = $totalAmount;
            $quotation->save();

            DB::commit();

            return redirect()->route('quotations.show', $quotation->id)
                ->with('success', 'Cotización creada satisfactoriamente.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()
                ->withInput()
                ->with('error', 'Error creating quotation: ' . $e->getMessage());
        }
    }
    public function show($id)
    {
        $quotation = Quotation::with([
            'customer',
            'products.origin',
            'products.destination',
            'products.incoterm',
            'products.quantityDescription',
            'services.service',
            'costDetails.cost'
        ])->findOrFail($id);

        // Estructura base similar al array de ejemplo
        $response = [
            'id' => $quotation->id,
            'NIT' => $quotation->customer_nit,
            'currency' => $quotation->currency,
            'exchange_rate' => $quotation->exchange_rate,
            'reference_number' => $quotation->reference_number,
            'products' => [],
            'services' => [],
            'costs' => []
        ];

        // Procesar productos
        foreach ($quotation->products as $key => $product) {
            $response['products'][$key + 1] = [
                'name' => $product->name,
                'origin_id' => (string)$product->origin_id,
                'destination_id' => (string)$product->destination_id,
                'weight' => (string)$product->weight,
                'incoterm_id' => (string)$product->incoterm_id,
                'quantity' => $product->quantity,
                'quantity_description_id' => (string)$product->quantity_description_id,
                'volume' => (string)$product->volume,
                'volume_unit' => $product->volume_unit,
                'description' => $product->description,
                // Agregar nombres adicionales
                'origin_name' => $product->origin->name,
                'destination_name' => $product->destination->name,
                'incoterm_name' => $product->incoterm->code,
                'quantity_description_name' => $product->quantityDescription->name
            ];
        }

        // Procesar servicios (manteniendo la estructura include/exclude)
        foreach ($quotation->services as $service) {
            $response['services'][$service->service_id] = $service->included ? 'include' : 'exclude';
            // Agregar nombre del servicio
            $response['service_names'][$service->service_id] = $service->service->name;
        }

        foreach ($quotation->costDetails as $costDetail) {
            $response['costs'][$costDetail->cost_id] = [
                'enabled' => '1',
                'amount' => (string)$costDetail->amount,
                'cost_id' => (string)$costDetail->cost_id,
                'concept' => $costDetail->concept,
                // Agregar nombre del costo
                'cost_name' => $costDetail->cost->name
            ];
        }

        $response['customer_info'] = [
            'name' => $quotation->customer->name,
            'email' => $quotation->customer->email,
            'phone' => $quotation->customer->phone
        ];

        return view('quotations.show', ['quotation_data' => $response]);
    }

    public function edit($id)
    {
        $quotation = Quotation::with([
            'customer',
            'products.origin.country',
            'products.destination.country',
            'products.incoterm',
            'products.quantityDescription',
            'services.service',
            'costDetails.cost'
        ])->findOrFail($id);

        // Estructura base para el formulario de edición
        $formData = [
            'id' => $quotation->id,
            'NIT' => $quotation->customer_nit,
            'reference_number' => $quotation->reference_number,
            'reference_customer' => $quotation->reference_customer,
            'currency' => $quotation->currency,
            'exchange_rate' => $quotation->exchange_rate,
            'reference_number' => $quotation->reference_number,
            'products' => [],
            'services' => [],
            'costs' => []
        ];

        // Procesar productos para edición
        foreach ($quotation->products as $key => $product) {
            $formData['products'][$key + 1] = [
                'name' => $product->name,
                'origin_id' => (string)$product->origin_id,
                'destination_id' => (string)$product->destination_id,
                'weight' => (string)$product->weight,
                'incoterm_id' => (string)$product->incoterm_id,
                'quantity' => $product->quantity,
                'quantity_description_id' => (string)$product->quantity_description_id,
                'volume' => (string)$product->volume,
                'volume_unit' => $product->volume_unit,
                'description' => $product->description,
                // Datos adicionales para mostrar en el formulario
                'origin_name' => $product->origin->name,
                'origin_country_id' => $product->origin->country->id,
                'destination_name' => $product->destination->name,
                'destination_country_id' => $product->destination->country->id,
                'incoterm_name' => $product->incoterm->code,
                'quantity_description_name' => $product->quantityDescription->name
            ];
        }

        // Procesar servicios para edición (formato include/exclude)
        foreach ($quotation->services as $service) {
            $formData['services'][$service->service_id] = $service->included ? 'include' : 'exclude';
        }

        // Procesar costos para edición
        foreach ($quotation->costDetails as $costDetail) {
            $formData['costs'][$costDetail->cost_id] = [
                'enabled' => '1', // Todos los costos guardados están habilitados
                'amount' => (string)$costDetail->amount,
                'cost_id' => (string)$costDetail->cost_id,
                'concept' => $costDetail->concept,
                'cost_name' => $costDetail->cost->name
            ];
        }

        // Obtener listas completas para los selects del formulario
        $formSelects = [
            'incoterms' => Incoterm::where('is_active', 1)->get(),
            'customers' => Customer::where('active', 1)->get(),
            'cities' => City::whereNull('deleted_at')->get(),
            'services' => Service::where('is_active', 1)->get(),
            'costs' => Cost::where('is_active', 1)->get(),
            'exchangeRates' => ExchangeRate::where('active', 1)->get(),
            'QuantityDescriptions' => QuantityDescription::where('is_active', 1)->get(),
        ];

        // Preparar ciudades por país para selects anidados


        return view(
            'quotations.edit',
            [
                'quotation_data' => [
                    'formData' => $formData, // Datos específicos de esta cotización
                    'formSelects' => $formSelects, // Listas completas para selects
                    'quotation_id' => $id, // ID de la cotización para el formulario,
                ],
            ]
        );
    }

    public function update(Request $request, $id)
    {
        // dd($request);
        // Validación de los datos de entrada
        $validatedData = $request->validate([
            'NIT' => 'required|exists:customers,NIT',
            'currency' => 'required|string|max:3',
            'exchange_rate' => 'required|numeric',
            'reference_customer' => 'nullable|string',
            'reference_number' => 'nullable|string',
            'products' => 'required|array',
            'products.*.name' => 'required|string',
            'products.*.origin_id' => 'required|exists:cities,id',
            'products.*.destination_id' => 'required|exists:cities,id',
            'products.*.incoterm_id' => 'required|exists:incoterms,id',
            'products.*.quantity' => 'required|string',
            'products.*.quantity_description_id' => 'required|exists:quantity_descriptions,id',
            'products.*.weight' => 'nullable|numeric',
            'products.*.volume' => 'nullable|numeric',
            'products.*.volume_unit' => 'nullable|string|max:10',
            'products.*.description' => 'nullable|string',
            'services' => 'required|array',
            'costs' => 'required|array',
            'costs.*.cost_id' => 'required|exists:costs,id',
            // 'costs.*.amount' => 'required|numeric',
            'costs.*.amount' => 'nullable|numeric',
            'costs.*.concept' => 'nullable|string'
        ]);

        DB::beginTransaction();

        try {
            // Obtener la cotización existente
            $quotation = Quotation::findOrFail($id);

            // Actualizar datos básicos de la cotización
            $quotation->update([
                'customer_nit' => $validatedData['NIT'],
                'delivery_date' => Carbon::now(),
                'currency' => $validatedData['currency'],
                'exchange_rate' => $validatedData['exchange_rate'],
                'reference_number' => $validatedData['reference_number'],
                'reference_customer' => $validatedData['reference_number'] ?? '',
                'amount' => 0 // Se recalculará al final
            ]);

            // Eliminar productos, servicios y costos existentes
            $quotation->products()->delete();
            $quotation->services()->delete();
            $quotation->costDetails()->delete();

            // Procesar y guardar los nuevos productos
            foreach ($validatedData['products'] as $productData) {
                $product = new Product([
                    'quotation_id' => $quotation->id,
                    'name' => $productData['name'] ?? null,
                    'origin_id' => $productData['origin_id'],
                    'destination_id' => $productData['destination_id'],
                    'incoterm_id' => $productData['incoterm_id'],
                    'quantity' => $productData['quantity'],
                    'quantity_description_id' => $productData['quantity_description_id'],
                    'weight' => $productData['weight'] ?? null,
                    'volume' => $productData['volume'] ?? null,
                    'volume_unit' => $productData['volume_unit'] ?? null,
                    'description' => $productData['description'] ?? $productData['name']
                ]);
                $product->save();
            }

            // Procesar y guardar los servicios
            foreach ($validatedData['services'] as $serviceId => $status) {
                // if (is_numeric($serviceId)) { // Asegurar que es un ID válido
                if (is_numeric($serviceId) && $status !== 'none') { // Asegurar que es un ID válido
                    $quotationService = new QuotationService([
                        'quotation_id' => $quotation->id,
                        'service_id' => $serviceId,
                        'included' => $status === 'include'
                    ]);
                    $quotationService->save();
                }
            }
            //TODO: Ver por que el enabled desaparece
            // Procesar y guardar los costos
            $totalAmount = 0;
            foreach ($validatedData['costs'] as $costId => $costData) {
                // dd($costData);
                if (isset($costData['amount'])) {
                // if (isset($costData['enabled'])) {
                    $costDetail = new CostDetail([
                        'quotation_id' => $quotation->id,
                        'cost_id' => $costData["cost_id"],
                        'amount' => $costData['amount'],
                        'currency' => $validatedData['currency'],
                        'concept' => $costData['concept'] ?? ''
                    ]);
                    $costDetail->save();

                    $totalAmount += $costData['amount'];
                }
            }

            // Actualizar el monto total de la cotización
            $quotation->amount = $totalAmount;
            $quotation->save();

            DB::commit();

            return redirect()->route('quotations.show', $quotation->id)
                ->with('success', 'Cotización actualizada exitosamente');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Error al actualizar la cotización: ' . $e->getMessage());
        }
    }


    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,approved,rejected,cancelled'
        ]);

        $quotation = Quotation::findOrFail($id);
        $quotation->status = $request->status;
        $quotation->save();

        return redirect()->route('quotations.show', $quotation->id)
            ->with('success', 'Estado de la cotización actualizado satisfactoriamente.');
    }


    public function destroy($id)
    {
        $quotation = Quotation::findOrFail($id);

        // Verificar permisos (solo admin o el creador puede eliminar)
        if (Auth::user()->role_id !== 1 && $quotation->users_id !== Auth::id()) {
            return back()->with('error', 'No tienes permiso para eliminar esta cotización');
        }

        DB::beginTransaction();

        try {
            $quotation->products()->delete();
            $quotation->services()->delete();
            $quotation->costDetails()->delete();

            $quotation->delete();

            DB::commit();

            return redirect()->route('quotations.index')
                ->with('success', 'Cotización eliminada exitosamente');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error al eliminar la cotización: ' . $e->getMessage());
        }
    }
    public function generarCotizacion(Request $request)
    {
        $validated = $request->validate([
            'quotation_id' => 'required|integer',
            'visible' => 'required|boolean'
        ]);
        $visible = $validated['visible'] ?? true;

        // Fetch quotation data from database using the validated quotation_id
        $quotation = Quotation::findOrFail($validated['quotation_id']);
        // Get client data from the quotation
        $clientData = $this->getClientData($quotation->customer_nit);

        // Get products, services and costs data from the quotation
        $productsData = $this->getProductsData($quotation->products);
        $servicesData = $this->getServicesData($quotation->services);
        $costsData = $this->getCostsData($quotation->costDetails);

        $totalCost = array_reduce($costsData, function ($carry, $item) {
            return $carry + floatval(str_replace(',', '', $item['amount']));
        }, 0);
        $totalCostFormatted = number_format($totalCost, 2, ',', '.');
        $deliveryDate = Carbon::parse($quotation->delivery_date)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');

        $quotationRef = $quotation->reference_number;

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Montserrat');
        $pageWidthInches = 8.52;
        $headerHeightInches = 2.26; // Altura deseada para la imagen del encabezado en pulgadas
        $footerHeightInches = 1.83; // Altura deseada para la imagen del pie de página en pulgadas

        $pageWidthPoints = $pageWidthInches * 72;
        $headerHeightPoints = $headerHeightInches * 72;
        $footerHeightPoints = $footerHeightInches * 72;

        $section = $phpWord->addSection([
            'paperSize' => 'Letter',
            'marginTop' => Converter::inchToTwip(2.26),
            'marginBottom' => Converter::inchToTwip(1.97),
        ]);

        if ($visible) {
            $header = $section->addHeader();
            $header->addImage(
                storage_path('app/templates/Herder.png'),
                [
                    'width' => $pageWidthPoints,
                    'height' => $headerHeightPoints,
                    'positioning' => 'absolute',
                    'posHorizontal' => \PhpOffice\PhpWord\Style\Image::POSITION_HORIZONTAL_LEFT,
                    'posHorizontalRel' => 'page',
                    'posVerticalRel' => 'page',
                    'marginTop' => 0,
                    'marginLeft' => 0
                ]
            );
            $footer = $section->addFooter();
            $footer->addImage(
                storage_path('app/templates/Footer.png'),
                [
                    'width' => $pageWidthPoints,
                    'height' => $footerHeightPoints,
                    'positioning' => 'absolute',
                    'posHorizontal' => \PhpOffice\PhpWord\Style\Image::POSITION_HORIZONTAL_LEFT,
                    'posHorizontalRel' => 'page',
                    'posVertical' => \PhpOffice\PhpWord\Style\Image::POSITION_VERTICAL_BOTTOM,
                    'posVerticalRel' => 'page',
                    'marginLeft' => 0,
                    'marginBottom' => 0
                ]
            );
        }

        $section->addText(
            $deliveryDate,
            ['size' => 11],
            ['spaceAfter' => Converter::pointToTwip(11), 'align' => 'right']
        );
        $section->addText(
            'Señores',
            ['size' => 11],
            ['spaceAfter' => Converter::pointToTwip(11)]
        );
        $section->addText(
            $clientData['name'],
            ['size' => 11, 'bold' => true, 'allCaps' => true],
            ['spaceAfter' => Converter::pointToTwip(11)]
        );
        $section->addText(
            'Presente. -',
            ['size' => 11],
            ['spaceAfter' => Converter::pointToTwip(11)]
        );
        $section->addText(
            'REF: COTIZACIÓN ' . $quotationRef,
            ['size' => 11, 'underline' => 'single', 'bold' => true, 'allCaps' => true],
            ['spaceAfter' => Converter::pointToTwip(11)]
        );
        $section->addText(
            'Estimado cliente, por medio la presente tenemos el agrado de enviarle nuestra cotización de acuerdo con su requerimiento e información proporcionada.',
            ['size' => 11],
            ['spaceAfter' => Converter::pointToTwip(11)]
        );

        // Crear tabla de datos del envío
        $tableStyle = [
            'borderColor' => '000000',
            'cellMarginLeft' => 50,
            'cellMarginRight' => 50,
            'layout' => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED
        ];
        $phpWord->addTableStyle('shipmentTable', $tableStyle);
        $table = $section->addTable('shipmentTable');
        $compactParagraphStyle = [
            'spaceBefore' => 0,
            'spaceAfter' => 0,
            'spacing' => 0,
            'lineHeight' => 1.0
        ];
        $table->addRow(Converter::cmToTwip(1.7));
        $table->addCell(Converter::cmToTwip(3), [
            'valign' => 'center',
            'bgColor' => 'bdd6ee',
            'borderSize' => 1,
        ])->addText('CLIENTE', [
            'bold' => true,
            'size' => 11,
            'allCaps' => true
        ], $compactParagraphStyle);
        $table->addCell(Converter::cmToTwip(7), [
            'valign' => 'center',
            'borderSize' => 1,
        ])->addText($clientData['name'], [
            'bold' => true,
            'allCaps' => true,
            'size' => 11
        ], $compactParagraphStyle);
        $table->addCell(Converter::cmToTwip(0.5), [
            'valign' => 'center',
        ]);

        // Segunda fila
        $table->addRow(Converter::cmToTwip(1.7));
        $table->addCell(Converter::cmToTwip(3), [
            'valign' => 'center',
            'bgColor' => 'bdd6ee',
            'borderSize' => 1,
        ])->addText('ORIGEN', [
            'bold' => true,
            'size' => 11
        ], $compactParagraphStyle);
        $table->addCell(Converter::cmToTwip(7), [
            'valign' => 'center',
            'borderSize' => 1,
        ])->addText($productsData[0]['origin']['city'] . ', ' . $productsData[0]['origin']['country'], [
            'size' => 11
        ], $compactParagraphStyle);
        $table->addCell(Converter::cmToTwip(0.5), [
            'valign' => 'center',
        ]);
        $table->addCell(Converter::cmToTwip(2), [
            'valign' => 'bottom',
            'bgColor' => 'bdd6ee',
            'borderSize' => 1,
        ])->addText('CANTIDAD', [
            'bold' => true,
            'size' => 11
        ], $compactParagraphStyle);
        $table->addCell(Converter::cmToTwip(3), [
            'valign' => 'bottom',
            'borderSize' => 1,
        ])->addText($productsData[0]['quantity']['value'], [
            'size' => 11
        ], $compactParagraphStyle);

        // Tercera fila
        $table->addRow(Converter::cmToTwip(1.7));
        $table->addCell(Converter::cmToTwip(3), [
            'valign' => 'center',
            'bgColor' => 'bdd6ee',
            'borderSize' => 1,
        ])->addText('DESTINO', [
            'bold' => true,
            'size' => 11
        ], $compactParagraphStyle);
        $table->addCell(Converter::cmToTwip(7), [
            'valign' => 'center',
            'borderSize' => 1,
        ])->addText($productsData[0]['destination']['city'] . ', ' . $productsData[0]['destination']['country'], [
            'size' => 11
        ], $compactParagraphStyle);
        $table->addCell(Converter::cmToTwip(0.5), [
            'valign' => 'center',
        ]);
        $table->addCell(Converter::cmToTwip(2), [
            'valign' => 'bottom',
            'bgColor' => 'bdd6ee',
            'borderSize' => 1,
        ])->addText('PESO', [
            'bold' => true,
            'size' => 11
        ], $compactParagraphStyle);
        $table->addCell(Converter::cmToTwip(3), [
            'valign' => 'bottom',
            'borderSize' => 1,
        ])->addText($productsData[0]['weight'] . " " . 'KG', [
            'size' => 11
        ], $compactParagraphStyle);

        // Cuarta fila
        $table->addRow();
        $table->addCell(Converter::cmToTwip(3), [
            'valign' => 'center',
            'bgColor' => 'bdd6ee',
            'borderSize' => 1,
        ])->addText('INCOTERM', [
            'bold' => true,
            'size' => 11
        ], $compactParagraphStyle);
        $table->addCell(Converter::cmToTwip(7), [
            'valign' => 'center',
            'borderSize' => 1,
        ])->addText($productsData[0]['incoterm'], [
            'size' => 11
        ], $compactParagraphStyle);

        $table->addCell(Converter::cmToTwip(0.5), [
            'valign' => 'center',
        ])->addText('');
        $table->addCell(Converter::cmToTwip(2), [
            'valign' => 'center',
            'bgColor' => 'bdd6ee',
            'borderSize' => 1,
        ])->addText($productsData[0]['volume']['unit'] == 'm3' ? 'M3' : 'KG/VOL', [
            'bold' => true,
            'size' => 11
        ], $compactParagraphStyle);
        $table->addCell(Converter::cmToTwip(3), [
            'valign' => 'center',
            'borderSize' => 1,
        ])->addText($productsData[0]['volume']['unit'] == 'm3' ? $productsData[0]['volume']['value'] . " " . 'M3' : $productsData[0]['volume']['value'] . " " . 'KG/VOL', [
            'size' => 11
        ], $compactParagraphStyle);
        //dd($productsData[0]['volume']['value']);

        // Texto después de la tabla
        $section->addTextBreak(1);
        $section->addText(
            'Para el requerimiento de transporte y logística los costos se encuentran líneas abajo',
            ['size' => 11],
            ['spaceAfter' => Converter::pointToTwip(11)]
        );

        // Opción de pago (en negrita)
        $textPago = $quotation->currency == 'USD' ?
            'OPCION 1) PAGO EN EFECTIVO EN USD DE ESTADOS UNIDOS' :
            'OPCION 1) PAGO EN EFECTIVO EN BS DE BOLIVIA';

        $section->addText(
            $textPago,
            ['bold' => true, 'size' => 11],
            ['spaceAfter' => Converter::pointToTwip(11)]
        );

        $table = $section->addTable([
            'width' => 400,
            'unit' => 'pct',
            'alignment' => JcTable::CENTER,
            'cellMargin' => 50,
        ]);
        // Primera fila de la tabla
        $table->addRow();
        $table->addCell(Converter::cmToTwip(10), [
            'valign' => 'center',
            'bgColor' => 'bdd6ee',
            'borderSize' => 1,
        ])->addText('CONCEPTO', [
            'bold' => true,
            'size' => 11,
            'allCaps' => true
        ], [
            'spaceBefore' => 0,
            'spaceAfter' => 0,
            'spacing' => 0,
            'lineHeight' => 1.0,
            'align' => 'center'
        ]);

        $textMonto = $quotation->currency == 'USD' ?
            'MONTO USD' :
            'MONTO BS';

        $table->addCell(Converter::cmToTwip(3), [
            'valign' => 'center',
            'bgColor' => 'bdd6ee',
            'borderSize' => 1,
        ])->addText($textMonto, [
            'bold' => true,
            'size' => 11,
            'allCaps' => true
        ], [
            'spaceBefore' => 0,
            'spaceAfter' => 0,
            'spacing' => 0,
            'lineHeight' => 1.0,
            'align' => 'right'
        ]);

        // Filas de costos
        foreach ($costsData as $cost) {
            $table->addRow();
            $table->addCell(Converter::cmToTwip(10), [
                'valign' => 'center',
                'borderSize' => 1,
            ])->addText($cost['name'], [
                'size' => 11
            ], [
                'spaceBefore' => 0,
                'spaceAfter' => 0,
                'spacing' => 0,
                'lineHeight' => 1.0,
                'align' => 'left'
            ]);
            $table->addCell(Converter::cmToTwip(3), [
                'valign' => 'center',
                'borderSize' => 1,
            ])->addText($cost['amount'], [
                'size' => 11
            ], [
                'spaceBefore' => 0,
                'spaceAfter' => 0,
                'spacing' => 0,
                'lineHeight' => 1.0,
                'align' => 'right'
            ]);
        }

        $table->addRow();
        $table->addCell(Converter::cmToTwip(10), [
            'valign' => 'center',
            'borderSize' => 1,
        ])->addText('TOTAL', [
            'size' => 11,
            'allCaps' => true
        ], [
            'spaceBefore' => 0,
            'spaceAfter' => 0,
            'spacing' => 0,
            'lineHeight' => 1.0,
            'align' => 'left'
        ]);
        $table->addCell(Converter::cmToTwip(3), [
            'valign' => 'center',
            'borderSize' => 1,
        ])->addText($totalCostFormatted, [
            'size' => 11,
            'allCaps' => true
        ], [
            'spaceBefore' => 0,
            'spaceAfter' => 0,
            'spacing' => 0,
            'lineHeight' => 1.0,
            'align' => 'right'
        ]);
        $section->addText(
            '** De acuerdo con el TC paralelo vigente.',
            [
                'size' => 11,
                'bold' => true
            ],
            [
                'spaceAfter' => Converter::pointToTwip(11),
                'spaceBefore' => Converter::pointToTwip(11),
            ]
        );
        $section->addText(
            'El servicio incluye:',
            ['size' => 11, 'bold' => true],
            ['spaceAfter' => Converter::pointToTwip(11)]
        );
        // Crear la lista con guiones
        $listStyleName = 'bulletStyle';
        $phpWord->addNumberingStyle(
            $listStyleName,
            array(
                'type' => 'singleLevel',
                'levels' => array(
                    array('format' => 'upperLetter', 'text' => '-', 'left' => 720, 'hanging' => 720, 'tabPos' => 1080),
                )
            )
        );
        // Añadir tu lista con los elementos
        foreach ($servicesData['included'] as $service) {
            $section->addListItem(
                $service,
                0,
                ['size' => 11],
                $listStyleName,
                [
                    'spaceAfter' => 0,
                    'spacing' => 0,
                    'lineHeight' => 1.0
                ]
            );
        }
        $section->addText(
            'El servicio no incluye:',
            ['size' => 11, 'bold' => true],
            [
                'spaceAfter' => Converter::pointToTwip(11),
                'spaceBefore' => Converter::pointToTwip(11)
            ]
        );
        foreach ($servicesData['excluded'] as $service) {
            $section->addListItem(
                $service,
                0,
                ['size' => 11],
                $listStyleName,
                [
                    'spaceAfter' => 0,
                    'spacing' => 0,
                    'lineHeight' => 1.0
                ]
            );
        }
        $paragraphStyle = array(
            'spaceBefore' => Converter::pointToTwip(11),
            'spaceAfter' => Converter::pointToTwip(11),
        );
        // Crear el párrafo con formato mixto
        $textrun = $section->addTextRun($paragraphStyle);
        $textrun->addText(
            'Seguro: ',
            [
                'bold' => true,
                'size' => 11,
            ]
        );
        $textrun->addText(
            'Se recomienda tener una póliza de seguro para el embarque, ofrecemos la misma de manera adicional considerando el 0.35% sobre el valor declarado, con un min de 30 usd, previa autorización por la compañía de seguros.',
            [
                'size' => 11,
            ]
        );
        $paragraphStyle = array(
            'spaceAfter' => Converter::pointToTwip(11),
        );
        // Crear el párrafo con formato mixto
        $textrun = $section->addTextRun($paragraphStyle);
        $textrun->addText(
            'Forma de pago: ',
            [
                'bold' => true,
                'size' => 11,
            ]
        );
        $textrun->addText(
            'Una vez se confirme el arribo del embarque a puerto de destino.',
            [
                'size' => 11,
            ]
        );
        // Crear el párrafo con formato mixto
        $textrun = $section->addTextRun($paragraphStyle);
        $textrun->addText(
            'Validez: ',
            [
                'bold' => true,
                'size' => 11,
            ]
        );
        $textrun->addText(
            'Los fletes son válidos hasta 10 días, posterior a ese tiempo, validar si los costos aún están vigentes.',
            [
                'size' => 11,
            ]
        );
        // Crear el párrafo con formato mixto
        $textrun = $section->addTextRun($paragraphStyle);
        $textrun->addText(
            'Observaciones: ',
            [
                'bold' => true,
                'size' => 11,
            ]
        );
        $textrun->addText(
            'Se debe considerar como un tiempo de tránsito 48 a 50 días hasta puerto de Iquique. ',
            [
                'size' => 11,
            ]
        );
        $section->addText(
            'Atentamente:',
            ['size' => 11],
            ['spaceAfter' => Converter::pointToTwip(11)]
        );

        // Get contact information from quotation
        $contactName = $quotation->contact_name ?? 'Aidee Callisaya.';
        $contactPosition = $quotation->contact_position ?? 'Responsable Comercial';

        $section->addText(
            $contactName,
            ['size' => 11]
        );
        $section->addText(
            $contactPosition,
            [
                'size' => 11,
                'bold' => true
            ]
        );

        // Create filename using quotation reference
        $cleanRef = str_replace('/', '_', $quotationRef);
        $filename = 'cotizacion_' . $cleanRef . '.docx';

        $tempFile = tempnam(sys_get_temp_dir(), 'PHPWord');
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempFile);

        // Descargar el archivo
        return response()->download($tempFile, $filename)->deleteFileAfterSend(true);
    }

    private function getClientData($nit)
    {
        $client = Customer::where('NIT', $nit)->firstOrFail();

        return [
            'nit' => $client->NIT,
            'name' => $client->name,
            'email' => $client->email,
            'phone' => $client->phone,
            'address' => $client->address
        ];
    }

    private function getProductsData($products)
    {
        $processedProducts = [];

        foreach ($products as $product) {
            // Suponiendo que estos modelos existen y tienen las relaciones correctas
            $origin = City::with('country')->findOrFail($product->origin_id);
            $destination = City::with('country')->findOrFail($product->destination_id);
            $incoterm = Incoterm::findOrFail($product->incoterm_id);
            $quantity_descripcion = QuantityDescription::findOrFail($product->quantity_description_id);

            $processedProducts[] = [
                'name' => $product->name,
                'origin' => [
                    'city' => $origin->name,
                    'country' => $origin->country->name
                ],
                'destination' => [
                    'city' => $destination->name,
                    'country' => $destination->country->name
                ],
                'weight' => $product->weight,
                'incoterm' => $incoterm->code,
                'quantity' => [
                    'value' => $product->quantity,
                    'unit' => $quantity_descripcion->name
                ],
                'volume' => [
                    'value' => $product->volume,
                    'unit' => $product->volume_unit
                ]
            ];
        }

        return $processedProducts;
    }

    private function getServicesData($quotationServices)
    {
        $included = [];
        $excluded = [];

        foreach ($quotationServices as $quotationService) {
            $service = $quotationService->service;

            if ($quotationService->included) {
                $included[] = $service->name;
            } else {
                $excluded[] = $service->name;
            }
        }

        return [
            'included' => $included,
            'excluded' => $excluded
        ];
    }

    private function getCostsData($costDetails)
    {
        $processedCosts = [];

        foreach ($costDetails as $costDetail) {
            $cost = $costDetail->cost;

            $processedCosts[] = [
                'name' => $cost->name,
                'description' => $cost->description ?? '',
                'amount' => $costDetail->amount,
                'currency' => $costDetail->currency
            ];
        }

        return $processedCosts;
    }
}
