<?php

namespace Webkul\Admin\Http\Controllers;

use Illuminate\Support\Facades\Event;
use Webkul\Core\Repositories\KanbanCardPreferenceRepository;

class KanbanCardPreferenceController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(protected KanbanCardPreferenceRepository $kanbanCardPreferenceRepository) {}

    /**
     * Retrieve the current user's kanban card preference for the given screen.
     */
    public function get()
    {
        $preference = $this->kanbanCardPreferenceRepository->findOneWhere([
            'user_id' => auth()->guard()->user()->id,
            'src'     => request('src'),
        ]);

        return response()->json([
            'data' => $preference,
        ]);
    }

    /**
     * Create or update the current user's kanban card preference for the given screen.
     */
    public function storeOrUpdate()
    {
        $this->validate(request(), [
            'src'         => 'required|string',
            'preferences' => 'required|array',
        ]);

        $userId = auth()->guard()->user()->id;

        $existingPreference = $this->kanbanCardPreferenceRepository->findOneWhere([
            'user_id' => $userId,
            'src'     => request('src'),
        ]);

        Event::dispatch('core.kanban_card_preference.update.before', $existingPreference);

        if ($existingPreference) {
            $preference = $this->kanbanCardPreferenceRepository->update([
                'preferences' => request('preferences'),
            ], $existingPreference->id);
        } else {
            $preference = $this->kanbanCardPreferenceRepository->create([
                'user_id'     => $userId,
                'src'         => request('src'),
                'preferences' => request('preferences'),
            ]);
        }

        Event::dispatch('core.kanban_card_preference.update.after', $preference);

        return response()->json([
            'data' => $preference,
        ]);
    }
}
