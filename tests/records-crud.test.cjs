const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

test('PDS API: full-field CRUD, validation, search, pagination, CSRF and conflicts', async () => {
  const base = process.env.PDS_TEST_URL || 'http://127.0.0.1:8088';
  const endpoint = base + '/api/records.php';
  const session = await fetch(endpoint + '?action=session');
  assert.equal(session.status, 200);
  const cookie = session.headers.get('set-cookie').split(';')[0];
  const { csrf } = await session.json();
  const tracked = new Map();
  const marker = 'CrudTest' + Date.now();
  async function api(method, query = '', body, token = csrf) {
    const response = await fetch(endpoint + query, {
      method, headers: { Cookie: cookie, 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: body === undefined ? undefined : JSON.stringify(body)
    });
    return { status: response.status, body: await response.json() };
  }
  const schema = JSON.parse(fs.readFileSync(path.join(__dirname, '../api/field-schema.json')));
  const fields = Object.fromEntries(Object.entries(schema).map(([key, type]) => [key,
    type === 'date' ? '2000-01-02' : type === 'number' ? '1.5' : type === 'select' || type === 'radio' ? '' : type === 'email' ? 'test@example.invalid' : 'Sample ' + key
  ]));
  fields.surname = marker; fields.firstname = 'Test'; fields.citizenship = 'Filipino';
  fields.education_college_school = '<script>alert("test")</script>';
  try {
    assert.equal((await api('POST', '', { fields }, '')).status, 403);
    const invalid = await api('POST', '', { fields: { surname: '', firstname: '' } });
    assert.equal(invalid.status, 422);
    assert.ok(invalid.body.errors.firstname);
    assert.equal((await api('POST', '', { fields: { ...fields, email: 'invalid' } })).status, 422);
    assert.equal((await api('POST', '', { fields: { ...fields, dateofbirth: '2025-02-30' } })).status, 422);
    const created = await api('POST', '', { fields });
    assert.equal(created.status, 201, JSON.stringify(created.body));
    const id = created.body.record.id; tracked.set(id, 1);
    const loaded = await api('GET', '?id=' + id);
    assert.deepEqual(loaded.body.record.fields, fields);
    assert.equal(Object.keys(loaded.body.record.fields).length, 98);
    fields.mobile = '09123456789'; fields.child_1_name = 'Updated child'; fields.citizenship = 'Dual Citizen';
    const edited = await api('PUT', '?id=' + id, { fields, version: 1 });
    assert.equal(edited.status, 200); tracked.set(id, 2);
    assert.equal(edited.body.record.version, 2);
    assert.deepEqual((await api('GET', '?id=' + id)).body.record.fields, fields);
    assert.equal((await api('PUT', '?id=' + id, { fields, version: 1 })).status, 409);
    assert.equal((await api('DELETE', '?id=' + id, { version: 1 })).status, 409);
    assert.equal((await api('GET', '?q=' + marker.toLowerCase())).body.total, 1);
    assert.equal((await api('GET', '?q=' + encodeURIComponent("' OR 1=1 --"))).body.total, 0);
    assert.equal((await api('GET', '?q=' + encodeURIComponent(marker + '%'))).body.total, 0);
    assert.equal((await api('GET', '?page=-1')).status, 400);
    for (let i = 0; i < 10; i++) {
      const extra = await api('POST', '', { fields: { surname: marker, firstname: 'Page' + i } });
      assert.equal(extra.status, 201); tracked.set(extra.body.record.id, 1);
    }
    const first = await api('GET', '?q=' + marker);
    assert.equal(first.body.total, 11); assert.equal(first.body.records.length, 10);
    const second = await api('GET', '?q=' + marker + '&page=2');
    assert.equal(second.body.records.length, 1);
    assert.ok(!first.body.records.some(row => row.id === second.body.records[0].id));
    assert.equal((await api('DELETE', '?id=' + id, { version: 2 })).status, 200);
    tracked.delete(id);
    assert.equal((await api('GET', '?id=' + id)).status, 404);
    assert.equal((await api('PUT', '?id=' + id, { fields, version: 2 })).status, 409);
  } finally {
    for (const [id, version] of tracked) {
      const removed = await api('DELETE', '?id=' + id, { version });
      assert.equal(removed.status, 200, 'Test data cleanup failed for ' + id);
    }
  }
});
