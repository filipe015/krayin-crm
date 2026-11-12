<?php

namespace Webkul\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Webkul\Admin\Helpers\Dashboard;
use Webkul\Admin\Helpers\Reporting\Lead as LeadReporting;

class DashboardController extends Controller
{
    /**
     * Request param functions
     *
     * @var array
     */
    protected $typeFunctions = [
        'over-all' => 'getOverAllStats',
        'revenue-stats' => 'getRevenueStats',
        'total-leads' => 'getTotalLeadsStats',
        'sales-cycle' => 'getSalesCycleStats',
        'revenue-by-sources' => 'getLeadsStatsBySources',
        'revenue-by-types' => 'getLeadsStatsByTypes',
        'top-selling-products' => 'getTopSellingProducts',
        'top-persons' => 'getTopPersons',
        'open-leads-by-states' => 'getOpenLeadsByStates',
    ];

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(protected Dashboard $dashboardHelper) {}

    /**
     * Display a listing of the resource.
     *
     * @return View
     */
    public function index()
    {
        return view('admin::dashboard.index')->with([
            'startDate' => $this->dashboardHelper->getStartDate(),
            'endDate' => $this->dashboardHelper->getEndDate(),
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function stats()
    {
        $stats = $this->dashboardHelper->{$this->typeFunctions[request()->query('type')]}();

        return response()->json([
            'statistics' => $stats,
            'date_range' => $this->dashboardHelper->getDateRange(),
        ]);
    }

    /**
     * The deals counted by a dashboard card (total or won) in the chosen period.
     */
    public function leads(LeadReporting $reporting): View
    {
        abort_unless(bouncer()->hasPermission('leads'), 401);

        $metric = request()->query('metric') === 'won' ? 'won' : 'total';

        $query = $reporting->leadsQuery($metric, $reporting->getStartDate(), $reporting->getEndDate())
            ->with(['person', 'stage', 'pipeline', 'user']);

        if ($userIds = bouncer()->getAuthorizedUserIds()) {
            $query->whereIn('leads.user_id', $userIds);
        }

        $leads = $query->orderBy($metric === 'won' ? 'closed_at' : 'created_at', 'desc')->paginate(25)->withQueryString();

        return view('admin::dashboard.leads', [
            'leads' => $leads,
            'metric' => $metric,
            'startDate' => $reporting->getStartDate(),
            'endDate' => $reporting->getEndDate(),
        ]);
    }
}
