\pset format unaligned
\pset fieldsep '|'
\pset tuples_only on
select s, 'persons', (xpath('/row/c/text()', query_to_xml(format('select count(*) c from %I.person', s), false, true, '')))[1]::text from unnest(array['p1','p2','p3','p4','p5','p6','p7','p8','p9','p10']) s;
