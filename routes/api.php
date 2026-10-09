<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use App\Models\Cm;
use App\Models\Project;
use App\Models\Firmware;
use App\Models\Image;
use App\Models\Script;
use App\Models\Label;
use App\Http\Controllers\AddImageController;
use App\Services\ProjectActivator;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
| Tokens are created on the user's profile page with the permissions
| create / read / update / delete.
|
*/

/* Routes to list all information of a certain group */

Route::middleware('auth:sanctum')->get('/cms', function (Request $request) {
    return Cm::orderBy('id')->get();
});

Route::middleware('auth:sanctum')->get('/projects/{projectId}/cms', function (Request $request, $projectId) {
    return Cm::where('project_id', $projectId)->orderBy('id')->get()->toJson();
});

Route::middleware('auth:sanctum')->get('/projects', function (Request $request) {
    return Project::with('scripts')->orderBy('name')->get();
});

Route::middleware('auth:sanctum')->get('/images', function (Request $request) {
    return Image::orderBy('filename')->orderBy('id')->get();
});

Route::middleware('auth:sanctum')->get('/firmware', function (Request $request) {
    return Firmware::all();
});

Route::middleware('auth:sanctum')->get('/scripts', function (Request $request) {
    return Script::orderBy('name')->get();
});

Route::middleware('auth:sanctum')->get('/labels', function (Request $request) {
    return Label::orderBy('name')->get();
});

/* Routes to add or update individual objects */

Route::middleware('auth:sanctum')->post('/images', function (Request $request) {
    if ($request->user()->tokenCan('create'))
    {
        $c = new AddImageController;
        return $c->store($request);
    }
    else
    {
        App::abort(403, "API user lacks 'create' permission");
    }
});

Route::middleware('auth:sanctum')->get('/images/{imageId}', function (Request $request, $imageId) {
    return Image::findOrFail($imageId);
});

Route::middleware('auth:sanctum')->delete('/images/{imageId}', function (Request $request, $imageId) {
    if ($request->user()->tokenCan('delete'))
    {
        Image::findOrFail($imageId)->delete();
    }
    else
    {
        App::abort(403, "API user lacks 'delete' permission");
    }
});

Route::middleware('auth:sanctum')->get('/projects/{projectId}', function (Request $request, $projectId) {
    return Project::with('scripts')->findOrFail($projectId);
});

/* Project fields can be changed one at a time; "scripts" is the full list of script ids to attach.
   Changing the active project rebuilds the EEPROM image the modules download. */
Route::middleware('auth:sanctum')->patch('/projects/{projectId}', function (Request $request, $projectId) {
    if (!$request->user()->tokenCan('update'))
    {
        App::abort(403, "API user lacks 'update' permission");
    }

    $data = $request->validate([
        'name' => 'sometimes|string|max:255',
        'device' => 'sometimes|in:cm4',
        'storage' => 'sometimes|string|max:255',
        'image_id' => 'sometimes|nullable|exists:images,id',
        'label_id' => 'sometimes|nullable|exists:labels,id',
        'label_moment' => 'sometimes|in:never,preinstall,postinstall',
        'eeprom_firmware' => 'sometimes|nullable|string|max:255',
        'eeprom_settings' => 'sometimes|nullable|string|max:2024',
        'verify' => 'sometimes|boolean',
        'scripts' => 'sometimes|array',
        'scripts.*' => 'integer|exists:scripts,id',
    ]);

    $project = Project::findOrFail($projectId);
    $project->update(collect($data)->except('scripts')->all());
    if (array_key_exists('scripts', $data))
    {
        $project->scripts()->sync($data['scripts']);
    }

    try
    {
        (new ProjectActivator)->refreshIfActive($project);
    }
    catch (\RuntimeException $e)
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    return $project->load('scripts');
});

/* Scripts (upstream #55) */

$scriptRules = [
    'name' => 'required|string|max:255|unique:scripts,name',
    'script_type' => ['required', Rule::in(['preinstall', 'postinstall'])],
    'priority' => 'sometimes|integer|min:0|max:1000',
    'bg' => 'sometimes|boolean',
    'script' => 'required|string',
];

Route::middleware('auth:sanctum')->post('/scripts', function (Request $request) use ($scriptRules) {
    if (!$request->user()->tokenCan('create'))
    {
        App::abort(403, "API user lacks 'create' permission");
    }

    $data = $request->validate($scriptRules);
    return response()->json(Script::create($data + ['priority' => 50, 'bg' => false]), 201);
});

Route::middleware('auth:sanctum')->get('/scripts/{scriptId}', function (Request $request, $scriptId) {
    return Script::findOrFail($scriptId);
});

Route::middleware('auth:sanctum')->patch('/scripts/{scriptId}', function (Request $request, $scriptId) use ($scriptRules) {
    if (!$request->user()->tokenCan('update'))
    {
        App::abort(403, "API user lacks 'update' permission");
    }

    $script = Script::findOrFail($scriptId);
    $rules = $scriptRules;
    $rules['name'] = ['sometimes', 'string', 'max:255', Rule::unique('scripts', 'name')->ignore($script->id)];
    $rules['script_type'] = ['sometimes', Rule::in(['preinstall', 'postinstall'])];
    $rules['script'] = 'sometimes|string';
    $script->update($request->validate($rules));
    return $script;
});

Route::middleware('auth:sanctum')->delete('/scripts/{scriptId}', function (Request $request, $scriptId) {
    if (!$request->user()->tokenCan('delete'))
    {
        App::abort(403, "API user lacks 'delete' permission");
    }

    $script = Script::findOrFail($scriptId);
    if ($script->projects()->count())
    {
        return response()->json(['message' => "Script is used by projects: ".$script->projects()->pluck('name')->implode(', ')], 409);
    }
    $script->delete();
    return response()->json(['deleted' => $script->id]);
});
