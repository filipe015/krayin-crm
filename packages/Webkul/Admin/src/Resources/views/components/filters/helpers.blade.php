{{--
    Small helpers shared by the two filter drawers (the kanban's and the data grid's), so a fix is made once:
    - formatFilterDate: how an applied date range is shown (dd/mm/aaaa); the value sent to the server is untouched.
    - loadCustomFieldOptions: a custom text field is filtered by picking one of its existing values; they
      are fetched when the field is picked (or already applied), not with the page.
--}}
@pushOnce('scripts')
    <script>
        window.formatFilterDate = function (value) {
            const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);

            if (! match) {
                return value;
            }

            return `${match[3]}/${match[2]}/${match[1]}` + (match[4] ? ` ${match[4]}:${match[5]}` : '');
        };

        window.loadCustomFieldOptions = async function (axios, column) {
            const lazy = column
                && column.index.startsWith('cf_')
                && column.filterable_type === 'dropdown'
                && column.type === 'string'
                && ! column.filterable_options.length
                && ! column.optionsRequested;

            if (! lazy) {
                return;
            }

            column.optionsRequested = true;

            try {
                const { data } = await axios.get("{{ route('admin.leads.custom_field_values') }}", { params: { field: column.index } });

                column.filterable_options = data;
            } catch (error) {
                column.optionsRequested = false;
            }
        };
    </script>
@endPushOnce
