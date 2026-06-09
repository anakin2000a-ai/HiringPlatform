# 03 - Database Schema DBML

```dbml
Project StoreBasedHiringWorkflowPlatform {
  database_type: "MySQL"
}

Table franchise_accounts {
  id bigint [pk, increment]
  name varchar(255) [not null]
  status varchar(50) [not null, default: 'active']
  created_at timestamp
  updated_at timestamp
}

Table stores {
  id bigint [pk, increment]
  franchise_account_id bigint [not null, ref: > franchise_accounts.id]
  name varchar(255) [not null]
  code varchar(100) [unique]
  address text
  city varchar(150)
  state varchar(150)
  country varchar(150)
  timezone varchar(100)
  status varchar(50) [not null, default: 'active']
  created_at timestamp
  updated_at timestamp
}

Table users {
  id bigint [pk, increment]
  franchise_account_id bigint [ref: > franchise_accounts.id]
  name varchar(255) [not null]
  email varchar(255) [not null, unique]
  password varchar(255) [not null]
  role varchar(100) [not null, default: 'viewer']
  access_scope varchar(50) [not null, default: 'store']
  status varchar(50) [not null, default: 'active']
  created_at timestamp
  updated_at timestamp
}

Table user_store_access {
  id bigint [pk, increment]
  user_id bigint [not null, ref: > users.id]
  store_id bigint [not null, ref: > stores.id]
  created_at timestamp
  updated_at timestamp

  indexes {
    (user_id, store_id) [unique]
  }
}

Table hiring_workflows {
  id bigint [pk, increment]
  store_id bigint [not null, ref: > stores.id]
  name varchar(255) [not null]
  version int [not null, default: 1]
  status varchar(50) [not null, default: 'draft']
  parent_workflow_id bigint [ref: > hiring_workflows.id]
  created_by bigint [ref: > users.id]
  published_at timestamp
  archived_at timestamp
  created_at timestamp
  updated_at timestamp

  indexes {
    (store_id, name, version) [unique]
    (store_id, status)
  }
}

Table workflow_stages {
  id bigint [pk, increment]
  hiring_workflow_id bigint [not null, ref: > hiring_workflows.id]
  name varchar(255) [not null]
  slug varchar(150) [not null]
  stage_type varchar(100) [not null]
  position int [not null]
  is_initial boolean [not null, default: false]
  is_terminal boolean [not null, default: false]
  auto_advance_enabled boolean [not null, default: false]
  configuration json
  created_at timestamp
  updated_at timestamp

  indexes {
    (hiring_workflow_id, slug) [unique]
    (hiring_workflow_id, position)
  }
}

Table workflow_stage_transitions {
  id bigint [pk, increment]
  hiring_workflow_id bigint [not null, ref: > hiring_workflows.id]
  from_stage_id bigint [ref: > workflow_stages.id]
  to_stage_id bigint [not null, ref: > workflow_stages.id]
  name varchar(255)
  is_manual_allowed boolean [not null, default: true]
  is_automatic_allowed boolean [not null, default: true]
  conditions json
  created_at timestamp
  updated_at timestamp

  indexes {
    (hiring_workflow_id, from_stage_id, to_stage_id) [unique]
  }
}

Table questionnaire_templates {
  id bigint [pk, increment]
  store_id bigint [not null, ref: > stores.id]
  name varchar(255) [not null]
  version int [not null, default: 1]
  status varchar(50) [not null, default: 'active']
  created_by bigint [ref: > users.id]
  created_at timestamp
  updated_at timestamp

  indexes {
    (store_id, name, version) [unique]
  }
}

Table questionnaire_questions {
  id bigint [pk, increment]
  questionnaire_template_id bigint [not null, ref: > questionnaire_templates.id]
  question_key varchar(150) [not null]
  label text [not null]
  type varchar(50) [not null]
  options json
  validation_rules json
  visibility_rules json
  is_required boolean [not null, default: false]
  position int [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (questionnaire_template_id, question_key) [unique]
    (questionnaire_template_id, position)
  }
}

Table stage_questionnaire_assignments {
  id bigint [pk, increment]
  workflow_stage_id bigint [not null, ref: > workflow_stages.id]
  questionnaire_template_id bigint [not null, ref: > questionnaire_templates.id]
  is_required boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp

  indexes {
    (workflow_stage_id, questionnaire_template_id) [unique]
  }
}

Table document_templates {
  id bigint [pk, increment]
  store_id bigint [not null, ref: > stores.id]
  name varchar(255) [not null]
  document_type varchar(100) [not null]
  requires_signature boolean [not null, default: false]
  description text
  created_by bigint [ref: > users.id]
  created_at timestamp
  updated_at timestamp
}

Table stage_document_requirements {
  id bigint [pk, increment]
  workflow_stage_id bigint [not null, ref: > workflow_stages.id]
  document_template_id bigint [not null, ref: > document_templates.id]
  is_required boolean [not null, default: true]
  due_days_after_stage_entry int
  created_at timestamp
  updated_at timestamp

  indexes {
    (workflow_stage_id, document_template_id) [unique]
  }
}

Table job_openings {
  id bigint [pk, increment]
  store_id bigint [not null, ref: > stores.id]
  hiring_workflow_id bigint [not null, ref: > hiring_workflows.id]
  title varchar(255) [not null]
  description text
  employment_type varchar(100)
  openings_count int [not null, default: 1]
  status varchar(50) [not null, default: 'draft']
  published_at timestamp
  closed_at timestamp
  created_by bigint [ref: > users.id]
  created_at timestamp
  updated_at timestamp

  indexes {
    (store_id, status)
    hiring_workflow_id
  }
}

Table applicants {
  id bigint [pk, increment]
  first_name varchar(150) [not null]
  last_name varchar(150) [not null]
  email varchar(255)
  phone varchar(50)
  birth_date date
  source varchar(100)
  metadata json
  created_at timestamp
  updated_at timestamp

  indexes {
    email
    phone
  }
}

Table applications {
  id bigint [pk, increment]
  applicant_id bigint [not null, ref: > applicants.id]
  job_opening_id bigint [not null, ref: > job_openings.id]
  current_stage_id bigint [ref: > workflow_stages.id]
  status varchar(50) [not null, default: 'active']
  score int
  applied_at timestamp
  rejected_at timestamp
  hired_at timestamp
  withdrawn_at timestamp
  created_at timestamp
  updated_at timestamp

  indexes {
    (applicant_id, job_opening_id) [unique]
    current_stage_id
    status
  }
}

Table applicant_answers {
  id bigint [pk, increment]
  application_id bigint [not null, ref: > applications.id]
  questionnaire_template_id bigint [not null, ref: > questionnaire_templates.id]
  questionnaire_question_id bigint [not null, ref: > questionnaire_questions.id]
  answer json
  answered_at timestamp
  created_at timestamp
  updated_at timestamp

  indexes {
    (application_id, questionnaire_question_id) [unique]
    application_id
  }
}

Table applicant_documents {
  id bigint [pk, increment]
  application_id bigint [not null, ref: > applications.id]
  workflow_stage_id bigint [not null, ref: > workflow_stages.id]
  stage_document_requirement_id bigint [not null, ref: > stage_document_requirements.id]
  document_template_id bigint [not null, ref: > document_templates.id]
  status varchar(50) [not null, default: 'pending']
  file_path text
  external_signature_id varchar(255)
  submitted_at timestamp
  signed_at timestamp
  approved_by bigint [ref: > users.id]
  approved_at timestamp
  rejected_at timestamp
  rejected_reason text
  expires_at timestamp
  created_at timestamp
  updated_at timestamp

  indexes {
    (application_id, stage_document_requirement_id) [unique]
    (application_id, workflow_stage_id)
  }
}

Table application_stage_transitions {
  id bigint [pk, increment]
  application_id bigint [not null, ref: > applications.id]
  from_stage_id bigint [ref: > workflow_stages.id]
  to_stage_id bigint [not null, ref: > workflow_stages.id]
  changed_by bigint [ref: > users.id]
  transition_type varchar(50) [not null]
  reason text
  metadata json
  created_at timestamp

  indexes {
    application_id
    to_stage_id
  }
}

Table workflow_activities {
  id bigint [pk, increment]
  application_id bigint [not null, ref: > applications.id]
  store_id bigint [not null, ref: > stores.id]
  workflow_stage_id bigint [ref: > workflow_stages.id]
  actor_type varchar(100)
  actor_id bigint
  event_type varchar(100) [not null]
  old_value json
  new_value json
  metadata json
  created_at timestamp

  indexes {
    application_id
    store_id
    workflow_stage_id
    event_type
  }
}

Table automation_rules {
  id bigint [pk, increment]
  store_id bigint [not null, ref: > stores.id]
  hiring_workflow_id bigint [ref: > hiring_workflows.id]
  workflow_stage_id bigint [ref: > workflow_stages.id]
  name varchar(255) [not null]
  trigger varchar(100) [not null]
  conditions json
  actions json
  priority int [not null, default: 100]
  is_active boolean [not null, default: true]
  created_by bigint [ref: > users.id]
  created_at timestamp
  updated_at timestamp

  indexes {
    (store_id, trigger)
    (hiring_workflow_id, trigger)
    (workflow_stage_id, trigger)
  }
}

Table configuration_copy_logs {
  id bigint [pk, increment]
  source_store_id bigint [not null, ref: > stores.id]
  target_store_id bigint [not null, ref: > stores.id]
  copied_by bigint [ref: > users.id]
  copied_items json
  metadata json
  created_at timestamp
}

Table outbox_events {
  id bigint [pk, increment]
  event_id char(36) [not null, unique]
  subject varchar(255) [not null]
  event_type varchar(255) [not null]
  payload json [not null]
  status varchar(50) [not null, default: 'pending']
  attempts int [not null, default: 0]
  available_at timestamp
  published_at timestamp
  last_error text
  created_at timestamp
  updated_at timestamp

  indexes {
    status
    subject
    available_at
  }
}

Table inbox_events {
  id bigint [pk, increment]
  event_id char(36) [not null, unique]
  subject varchar(255) [not null]
  event_type varchar(255)
  payload json [not null]
  status varchar(50) [not null, default: 'pending']
  attempts int [not null, default: 0]
  processed_at timestamp
  last_error text
  created_at timestamp
  updated_at timestamp

  indexes {
    status
    subject
  }
}
```
