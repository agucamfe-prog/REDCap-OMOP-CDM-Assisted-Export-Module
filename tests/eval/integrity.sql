-- Integrity checks of a CDM schema loaded with the load kit (spec 018).
--
--   psql -U postgres -d cdm -At -F'|' -v s=p1 -f tests/eval/integrity.sql
--
-- Output: schema|check|issues. All ten must give 0.
-- They are the same queries that checks.sh runs for the ten schemas p1…p10.

-- 1. Facts of a person who is not in PERSON
select :'s', 'fk_person_broken', count(*) from (
  select person_id from :"s".measurement union all select person_id from :"s".condition_occurrence
  union all select person_id from :"s".drug_exposure union all select person_id from :"s".observation
  union all select person_id from :"s".note union all select person_id from :"s".death
  union all select person_id from :"s".visit_occurrence union all select person_id from :"s".observation_period) h
 where person_id not in (select person_id from :"s".person);

-- 2. Facts pointing to a visit that does not exist
select :'s', 'fk_visit_broken', count(*) from (
  select visit_occurrence_id v from :"s".measurement union all select visit_occurrence_id from :"s".condition_occurrence
  union all select visit_occurrence_id from :"s".drug_exposure union all select visit_occurrence_id from :"s".observation
  union all select visit_occurrence_id from :"s".note) h
 where v is not null and v not in (select visit_occurrence_id from :"s".visit_occurrence);

-- 3. Persons or visits with a care site that is not in CARE_SITE
select :'s', 'fk_care_site_broken', count(*) from (
  select care_site_id c from :"s".person union all select care_site_id from :"s".visit_occurrence) x
 where c is not null and c not in (select care_site_id from :"s".care_site);

-- 4. Persons, visits or measurements with a provider that is not in PROVIDER
select :'s', 'fk_provider_broken', count(*) from (
  select provider_id p from :"s".person union all select provider_id from :"s".visit_occurrence
  union all select provider_id from :"s".measurement) x
 where p is not null and p not in (select provider_id from :"s".provider);

-- 5. Facts dated outside the visit they belong to
select :'s', 'fact_outside_visit', count(*) from (
  select measurement_date d, visit_occurrence_id v from :"s".measurement
  union all select condition_start_date, visit_occurrence_id from :"s".condition_occurrence
  union all select drug_exposure_start_date, visit_occurrence_id from :"s".drug_exposure
  union all select observation_date, visit_occurrence_id from :"s".observation) h
  join :"s".visit_occurrence vo on vo.visit_occurrence_id = h.v
 where h.d not between vo.visit_start_date and vo.visit_end_date;

-- 6. Facts outside the person's observation period
select :'s', 'fact_outside_period', count(*) from (
  select person_id, measurement_date d from :"s".measurement
  union all select person_id, condition_start_date from :"s".condition_occurrence
  union all select person_id, drug_exposure_start_date from :"s".drug_exposure
  union all select person_id, observation_date from :"s".observation) h
  join :"s".observation_period o using (person_id)
 where h.d not between o.observation_period_start_date and o.observation_period_end_date;

-- 7. Facts before the year of birth
select :'s', 'fact_before_birth', count(*) from (
  select person_id, measurement_date d from :"s".measurement
  union all select person_id, condition_start_date from :"s".condition_occurrence
  union all select person_id, drug_exposure_start_date from :"s".drug_exposure) h
  join :"s".person p using (person_id)
 where extract(year from h.d) < p.year_of_birth;

-- 8. Facts after death (with one day of margin)
select :'s', 'fact_after_death', count(*) from (
  select person_id, measurement_date d from :"s".measurement
  union all select person_id, condition_start_date from :"s".condition_occurrence
  union all select person_id, drug_exposure_start_date from :"s".drug_exposure) h
  join :"s".death x using (person_id)
 where h.d > x.death_date + 1;

-- 9. Exposures that end before they start
select :'s', 'end_before_start', count(*) from :"s".drug_exposure
 where drug_exposure_end_date < drug_exposure_start_date;

-- 10. Persons without an observation period
select :'s', 'person_without_period', count(*) from :"s".person
 where person_id not in (select person_id from :"s".observation_period);
