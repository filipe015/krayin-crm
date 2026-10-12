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
                        @mouseover="hoveringMenu='{{$menuItem->getKey()}}'; flyoutTop = $event.currentTarget.getBoundingClientRect().top"
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

                    @php
                        $hasSubmenu = ! in_array($menuItem->getKey(), ['settings', 'configuration']) && $menuItem->haveChildren();
                    @endphp

                    <!-- Name of the item, shown on hover/focus while the menu is closed -->
                    @unless ($hasSubmenu)
                    <span
                        role="tooltip"
                        class="pointer-events-none absolute top-1/2 z-[10003] hidden -translate-y-1/2 whitespace-nowrap rounded-md bg-gray-900 px-2.5 py-1 text-xs font-medium text-white shadow-lg ltr:left-full ltr:ml-1 rtl:right-full rtl:mr-1 group-[.sidebar-collapsed]/container:group-hover/item:block dark:bg-gray-100 dark:text-gray-900"
                    >
                        {{ core()->getConfigData('general.settings.menu.'.$menuItem->getKey()) ?: $menuItem->getName() }}
                    </span>
                    @endunless

                    <!-- Submenu: a floating card next to the item, opened by hover or keyboard focus -->
                    @if ($hasSubmenu)
                        <div
                            class="fixed z-[10003] hidden min-w-[210px] flex-col gap-0.5 rounded-xl border border-gray-200 bg-white p-2 shadow-xl group-hover/item:flex group-focus-within/item:flex dark:border-gray-700 dark:bg-gray-900 before:absolute before:inset-y-0 before:w-2 before:content-[''] ltr:before:-left-2 rtl:before:-right-2 ltr:left-[208px] rtl:right-[208px] ltr:group-[.sidebar-collapsed]/container:left-[78px] rtl:group-[.sidebar-collapsed]/container:right-[78px]"
                            :class="{ '!flex': isMenuActive && hoveringMenu == '{{ $menuItem->getKey() }}' }"
                            :style="{ top: Math.max(flyoutTop - 8, 68) + 'px' }"
                        >
                            <p class="px-3 pb-1 pt-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ core()->getConfigData('general.settings.menu.'.$menuItem->getKey()) ?: $menuItem->getName() }}
                            </p>

                            @foreach ($menuItem->getChildren() as $subMenuItem)
                                <a
                                    href="{{ $subMenuItem->getUrl() }}"
                                    class="flex items-center rounded-lg px-3 py-2 text-sm font-medium transition-colors {{ $subMenuItem->isActive() ? 'bg-brandColor text-white' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800' }}"
                                >
                                    {{ core()->getConfigData('general.settings.menu.'.$subMenuItem->getKey()) ?: $subMenuItem->getName() }}
                                </a>
                            @endforeach
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