<!-- Navigation -->
<nav class="navbar navbar-default navbar-static-top" role="navigation" style="margin-bottom: 0">
    <div class="navbar-header">
        <a class="navbar-brand" href="{{ URL::to('/') }}">
            <img width="90" src="{{ asset('images/most.png') }}" />
        </a>
    </div>
    <!-- /.navbar-header -->
    <ul class="nav navbar-top-links navbar-left">
        @if (Auth::check())
        <li> {{ Auth::user()->username }} </li>
        @endif
        <!-- /.dropdown -->
        <li class="dropdown">
            <a class="dropdown-toggle" data-toggle="dropdown" href="#">
                <i class="fa fa-user fa-fw"></i> <i class="fa fa-caret-down"></i>
            </a>
            <ul class="dropdown-menu dropdown-user">
                <li><a href="{{ URL::to('/facility/1/edit') }}"><i class="fa fa-gear fa-fw"></i> بيانات المنشاه</a></li>
                <li class="divider"></li>
                <li><a href="{{ URL::to('/logout') }}"><i class="fa fa-sign-out fa-fw"></i> خروج</a></li>
            </ul>
        </li>
    </ul>
    @include('layouts.sidebar')
</nav>
