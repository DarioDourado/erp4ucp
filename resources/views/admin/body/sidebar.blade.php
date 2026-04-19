<div class="vertical-menu">

    <div data-simplebar class="h-100">

        <!-- User details -->


        <!--- Sidemenu -->
        <div id="sidebar-menu">
            <!-- Left Menu Start -->
            <ul class="metismenu list-unstyled" id="side-menu">
                <li class="menu-title">Menu</li>


                <!-- DASHBOARD -->
                <li>
                    <a href="{{ url('/dashboard') }}" class="waves-effect">
                        <i class="ri-dashboard-line"></i><span class="badge rounded-pill bg-success float-end">5</span>
                        <span>Dashboard</span>
                    </a>
                </li>


                <!--- TABELAS E TRANSAÇÕES -->
                <li class="menu-title">Tabelas</li>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="ri-team-line"></i>
                        <span>Business Partners</span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="customers.html">Customers</a></li>
                        <li><a href="leads.html">Leads</a></li>
                    </ul>
                </li>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="ri-table-2"></i>
                        <span>Common Tables</span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="{{ route('postalCode.all') }}">Postal Codes</a></li>
                        <li><a href="{{ route('supplier.all') }}">Suppliers</a></li>
                        <li><a href="{{ route('family.all') }}">Family Products</a></li>
                        <li><a href="{{ route('taxRate.all') }}">Tax Rates</a></li>
                        <li><a href="{{ route('unitMeasure.all') }}">Measurement Units</a></li>
                    </ul>
                </li>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="ri-table-2"></i>
                        <span>Transações</span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="tables-basic.html">Basic Tables</a></li>
                        <li><a href="tables-datatable.html">Data Tables</a></li>
                        <li><a href="tables-responsive.html">Responsive Table</a></li>
                        <li><a href="tables-editable.html">Editable Table</a></li>
                    </ul>
                </li>


                <!--- REPORTING -->
                <li class="menu-title">Reporting</li>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="ri-layout-3-line"></i>
                        <span>Layouts</span>
                    </a>
                    <ul class="sub-menu" aria-expanded="true">
                        <li>
                            <a href="javascript: void(0);" class="has-arrow">Vertical</a>
                            <ul class="sub-menu" aria-expanded="true">
                                <li><a href="layouts-dark-sidebar.html">Dark Sidebar</a></li>
                                <li><a href="layouts-compact-sidebar.html">Compact Sidebar</a></li>
                                <li><a href="layouts-icon-sidebar.html">Icon Sidebar</a></li>
                                <li><a href="layouts-boxed.html">Boxed Layout</a></li>
                                <li><a href="layouts-preloader.html">Preloader</a></li>
                                <li><a href="layouts-colored-sidebar.html">Colored Sidebar</a></li>
                            </ul>
                        </li>

                        <li>
                            <a href="javascript: void(0);" class="has-arrow">Horizontal</a>
                            <ul class="sub-menu" aria-expanded="true">
                                <li><a href="layouts-horizontal.html">Horizontal</a></li>
                                <li><a href="layouts-hori-topbar-light.html">Topbar light</a></li>
                                <li><a href="layouts-hori-boxed-width.html">Boxed width</a></li>
                                <li><a href="layouts-hori-preloader.html">Preloader</a></li>
                                <li><a href="layouts-hori-colored-header.html">Colored Header</a></li>
                            </ul>
                        </li>
                    </ul>
                </li>

                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="ri-bar-chart-line"></i>
                        <span>Analitics</span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="charts-apex.html">Apex Charts</a></li>
                        <li><a href="charts-chartjs.html">Chartjs Charts</a></li>
                        <li><a href="charts-flot.html">Flot Charts</a></li>
                        <li><a href="charts-knob.html">Jquery Knob Charts</a></li>
                        <li><a href="charts-sparkline.html">Sparkline Charts</a></li>
                    </ul>
                </li>


                <!--- OUTROS -->
                <li class="menu-title">Outros</li>
                <li>
                    <a href="javascript: void(0);" class="has-arrow waves-effect">
                        <i class="ri-profile-line"></i>
                        <span>Utilitários</span>
                    </a>
                    <ul class="sub-menu" aria-expanded="false">
                        <li><a href="pages-starter.html">Starter Page</a></li>
                        <li><a href="pages-timeline.html">Timeline</a></li>
                        <li><a href="pages-directory.html">Directory</a></li>
                        <li><a href="pages-invoice.html">Invoice</a></li>
                        <li><a href="pages-404.html">Error 404</a></li>
                        <li><a href="pages-500.html">Error 500</a></li>
                    </ul>
                </li>





            </ul>
        </div>
        <!-- Sidebar -->
    </div>
</div>