<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AlertNotification;
use Illuminate\Http\Request;

class AlertsController extends Controller
{
    public function index()
    {
        $alerts     = AlertNotification::orderBy('id')->get();
        $typeLabels = AlertNotification::typeLabels();

        return view('dashboard.alerts.index', compact('alerts', 'typeLabels'));
    }

    public function create()
    {
        $typeLabels = AlertNotification::typeLabels();
        return view('dashboard.alerts.form', compact('typeLabels'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'alert_type' => 'required|string',
            'status'     => 'required|in:active,inactive',
            'send_to'    => 'required|string',
            'cc'         => 'nullable|string',
            'bcc'        => 'nullable|string',
            'subject'    => 'required|string|max:255',
            'body'       => 'required|string',
        ]);

        AlertNotification::create($data);

        return redirect()->route('dashboard.alerts.index')
            ->with('success', 'Alert notification created.');
    }

    public function edit(AlertNotification $alert)
    {
        $typeLabels = AlertNotification::typeLabels();
        return view('dashboard.alerts.form', compact('alert', 'typeLabels'));
    }

    public function update(Request $request, AlertNotification $alert)
    {
        $data = $request->validate([
            'alert_type' => 'required|string',
            'status'     => 'required|in:active,inactive',
            'send_to'    => 'required|string',
            'cc'         => 'nullable|string',
            'bcc'        => 'nullable|string',
            'subject'    => 'required|string|max:255',
            'body'       => 'required|string',
        ]);

        $alert->update($data);

        return redirect()->route('dashboard.alerts.index')
            ->with('success', 'Alert notification updated.');
    }

    public function toggle(AlertNotification $alert)
    {
        $alert->update([
            'status' => $alert->isActive()
                ? AlertNotification::STATUS_INACTIVE
                : AlertNotification::STATUS_ACTIVE,
        ]);

        return back()->with('success', 'Alert status updated.');
    }

    public function destroy(Request $request)
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return back()->with('error', 'No alerts selected.');
        }

        AlertNotification::whereIn('id', $ids)->delete();

        return back()->with('success', count($ids) . ' alert(s) deleted.');
    }
}