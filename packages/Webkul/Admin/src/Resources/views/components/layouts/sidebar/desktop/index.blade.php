<div
    ref="sidebar"
    class="duration-80 fixed top-[60px] z-[10002] h-full w-[200px] border-gray-300 bg-white pt-4 transition-all group-[.sidebar-collapsed]/container:w-[70px] dark:border-gray-800 dark:bg-gray-900 max-lg:hidden ltr:border-r rtl:border-l"
>
    <div class="journal-scroll h-[calc(100vh-170px)] overflow-y-auto overflow-x-hidden group-[.sidebar-collapsed]/container:overflow-visible">
        <nav class="sidebar-rounded grid w-full gap-2">
            <!-- Navigation Menu -->
            @foreach (menu()->getItems('admin') as $menuItem)
                <div class="relative px-4 group/item {{ $menuItem->isActive() ? 'active' : 'inactive' }}">
                    <a
                        aria-label="{{ core()->getConfigData('general.settings.menu.'.$menuItem->getKey()) ?: $menuItem->getName() }}"
                        class="flex gap-2 p-1.5 items-center cursor-pointer hover:rounded-lg {{ $menuItem->isActive() == 'active' ? 'bg-brandColor rounded-lg' : ' hover:bg-gray-100 hover:dark:bg-gray-950' }} peer"
                        href="{{ ! in_array($menuItem->getKey(), ['settings', 'configuration']) && $menuItem->haveChildren() ? 'javascript:void(0)' : $menuItem->getUrl() }}"
                        @mouseleave="!isMenuActive ? hoveringMenu = '' : {}"
                        @mouseover="hoveringMenu='{{$menuItem->getKey()}}'"
                        @click="isMenuActive = !isMenuActive"
                    >
                        <span class="{{ $menuItem->getIcon() }} text-2xl {{ $menuItem->isActive() ? 'text-white' : ''}}"></span>

                        <div class="flex-1 flex justify-between items-center text-gray-600 dark:text-gray-300 font-medium whitespace-nowrap group-[.sidebar-collapsed]/container:hidden {{ $menuItem->isActive() ? 'text-white' : ''}} group">
                            <p>{{ core()->getConfigData('general.settings.menu.'.$menuItem->getKey()) ?: $menuItem->getName() }}</p>
                        
                            @if ( ! in_array($menuItem->getKey(), ['settings', 'configuration']) && $menuItem->haveChildren())
                                <i class="icon-right-arrow rtl:icon-left-arrow invisible text-2xl group-hover/item:visible {{ $menuItem->isActive() ? 'text-white' : ''}}"></i>
                            @endif
                        </div>
                    </a>

                    <!-- Name of the item, shown on hover/focus while the menu is closed -->
                    <span
                        role="tooltip"
                        class="pointer-events-none absolute top-1/2 z-[10003] hidden -translate-y-1/2 whitespace-nowrap rounded-md bg-gray-900 px-2.5 py-1 text-xs font-medium text-white shadow-lg ltr:left-full ltr:ml-1 rtl:right-full rtl:mr-1 group-[.sidebar-collapsed]/container:group-hover/item:block dark:bg-gray-100 dark:text-gray-900"
                    >
                        {{ core()->getConfigData('general.settings.menu.'.$menuItem->getKey()) ?: $menuItem->getName() }}
                    </span>

                    <!-- Submenu -->
                    @if (
                        ! in_array($menuItem->getKey(), ['settings', 'configuration'])
                        && $menuItem->haveChildren()
                    )
                        <div
                            class="absolute top-0 hidden flex-col bg-gray-100 ltr:left-[200px] rtl:right-[199px] ltr:group-[.sidebar-collapsed]/container:left-[70px] rtl:group-[.sidebar-collapsed]/container:right-[69px]"
                            :class="[isMenuActive && (hoveringMenu == '{{$menuItem->getKey()}}') ? '!flex' : 'hidden']"
                        >
                            <div class="sidebar-rounded fixed z-[1000] h-full min-w-[140px] max-w-max bg-white pt-4 after:-right-[30px] dark:border-gray-800 dark:bg-gray-900 max-lg:hidden ltr:border-r rtl:border-x">
                                <div class="journal-scroll h-[calc(100vh-100px)] overflow-hidden">
                                    <nav class="grid w-full gap-2">
                                        @foreach ($menuItem->getChildren() as $subMenuItem)
                                            <div class="px-4 group/item {{ $menuItem->isActive() ? 'active' : 'inactive' }}">
                                                <a
                                                    href="{{ $subMenuItem->getUrl() }}"
                                                    class="flex gap-2.5 p-2 items-center cursor-pointer hover:rounded-lg {{ $subMenuItem->isActive() == 'active' ? 'bg-brandColor rounded-lg' : ' hover:bg-gray-100 hover:dark:bg-gray-950' }} peer"
                                                >
                                                    <p class="text-gray-600 dark:text-gray-300 font-medium whitespace-nowrap {{ $subMenuItem->isActive() ? 'text-white' : ''}}">
                                                        {{ core()->getConfigData('general.settings.menu.'.$subMenuItem->getKey()) ?? $subMenuItem->getName() }}
                                                    </p>
                                                </a>
                                            </div>
                                        @endforeach
                                    </nav>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </nav>
    </div>

    <!-- Open / close the menu -->
    <div class="absolute inset-x-0 bottom-[76px] border-t border-gray-200 px-4 pt-3 dark:border-gray-800">
        <button
            type="button"
            class="flex w-full items-center gap-2 rounded-lg p-1.5 text-gray-600 hover:bg-gray-100 dark:text-gray-300 hover:dark:bg-gray-950"
            :aria-expanded="sidebarOpen ? 'true' : 'false'"
            aria-label="@lang('admin::app.layouts.sidebar-toggle')"
            @click="toggleSidebar"
        >
            <span
                class="icon-left-arrow text-2xl transition-transform duration-200 rtl:icon-right-arrow group-[.sidebar-collapsed]/container:rotate-180"
            ></span>

            <span class="whitespace-nowrap font-medium group-[.sidebar-collapsed]/container:hidden">
                @lang('admin::app.layouts.sidebar-collapse')
            </span>
        </button>
    </div>
</div>