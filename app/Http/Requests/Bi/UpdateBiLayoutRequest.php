<?php

namespace App\Http\Requests\Bi;

use App\Models\BiDashboard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The payload a drag produces: every card on the board, with its new slot.
 *
 * Bounds only. The numbers are clamped again by LayoutUpdater and a third time
 * by `BiWidget::slot()` on read — which is not paranoia so much as three
 * different lifetimes: this catches a malformed request, the updater catches a
 * client that disagrees about the column count, and the model catches a row
 * that is already wrong. Only the last one protects a board whose rows were
 * written before a rule changed.
 */
class UpdateBiLayoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'layout' => ['required', 'array', 'max:'.BiDashboard::MAX_WIDGETS],
            'layout.*.id' => ['required', 'integer', 'min:1'],
            'layout.*.x' => ['required', 'integer', 'min:0', 'max:11'],
            'layout.*.y' => ['required', 'integer', 'min:0', 'max:10000'],
            'layout.*.w' => ['required', 'integer', 'min:2', 'max:12'],
            'layout.*.h' => ['required', 'integer', 'min:2', 'max:24'],
        ];
    }
}
