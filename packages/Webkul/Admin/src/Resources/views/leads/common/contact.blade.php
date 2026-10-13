{!! view_render_event('admin.leads.create.contact_person.form_controls.before') !!}

<v-contact-component :data="person"></v-contact-component>

{!! view_render_event('admin.leads.create.contact_person.form_controls.after') !!}

@include('admin::leads.common.contact-component')
