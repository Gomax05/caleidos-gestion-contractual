/* Integración real PHP/SQLite. No requiere paquetes npm. */
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const assert = require('node:assert/strict');
const { test } = require('node:test');
const root = path.resolve(__dirname, '..');

test('HTTP: acceso, pantallas, permisos, CSRF, PDFs, versiones y ciclo contractual', { timeout: 60000 }, async () => {
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'caleidos-test-'));
    const listener = net.createServer();
    await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise(resolve => listener.close(resolve));
    const server = spawn(process.env.PHP_BINARY || 'php', ['-d', 'upload_max_filesize=64M', '-d', 'post_max_size=70M', '-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public'), path.join(root, 'public/router.php')], { cwd: root, env: { ...process.env, DB_DRIVER: 'sqlite', DB_SQLITE_PATH: path.join(temp, 'test.sqlite'), CALEIDOS_STORAGE_PATH: path.join(temp, 'storage') }, windowsHide: true });
    let logs = ''; server.stderr.on('data', chunk => { logs += chunk; });
    let cookie = ''; let token = ''; let html = '';
    const base = `http://127.0.0.1:${port}/`;
    async function request(url = 'index.php', data) {
        const response = await fetch(new URL(url, base), { method: data ? 'POST' : 'GET', headers: cookie ? { cookie } : {}, body: data, redirect: 'manual' });
        const setCookie = response.headers.get('set-cookie');
        if (setCookie) cookie = setCookie.split(';')[0];
        html = await response.text();
        const match = html.match(/name="csrf_token" value="([a-f0-9]+)"/);
        if (match) token = match[1];
        assert.notEqual(response.status, 500, logs);
        return response;
    }
    async function post(action, fields = {}, url = 'index.php') {
        const form = new FormData();
        form.set('csrf_token', token); form.set('action', action);
        for (const [name, value] of Object.entries(fields)) {
            if (Array.isArray(value)) value.forEach(item => form.append(name, item)); else form.set(name, value);
        }
        return request(url, form);
    }
    async function follow(response) {
        assert.equal(response.status, 303);
        for (let i = 0; i < 5 && response.status === 303; i++) response = await request(response.headers.get('location'));
        assert.equal(response.status, 200); return response;
    }
    try {
        let ready = false;
        for (let i = 0; i < 50; i++) {
            try { await request('index.php?health=1'); ready = true; break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
        }
        assert.ok(ready, logs);
        await request(); assert.match(html, /Buenos acuerdos/);
        await follow(await post('login', { email: 'comercial@caleidos.pe', password: 'incorrecta' })); assert.match(html, /incorrectos/);
        await follow(await post('login', { email: 'comercial@caleidos.pe', password: 'demo1234' })); assert.match(html, /Panel de mi área/);
        await request(); assert.match(html, /Buenos acuerdos/); assert.doesNotMatch(html, /class="app-shell"/);
        await request('index.php?page=login'); assert.match(html, /Crear usuario/); assert.match(html, /Gestionar usuarios/);
        await request('index.php?page=login&manage_users=1'); assert.match(html, /Ingresa como Administrador/);
        await request('index.php?page=dashboard'); assert.match(html, /Panel de mi área/);
        for (const page of ['dashboard','contracts','reviews','drafts','new','documents','observations','timeline','workflow','detail&id=CL-2026-0012']) { const r = await request(`index.php?page=${page}`); assert.equal(r.status, 200); assert.doesNotMatch(html, /Warning:|Fatal error|No se pudo completar/); }
        const protectedRequest = new FormData(); protectedRequest.set('action', 'approve'); protectedRequest.set('contract_id', 'CL-2026-0012');
        await follow(await request('index.php?page=detail&id=CL-2026-0012', protectedRequest)); assert.match(html, /formulario venció/);
        await follow(await post('approve', { contract_id: 'CL-2026-0012' }, 'index.php?page=detail&id=CL-2026-0012')); assert.match(html, /responsable de la fase/);
        const pdf = new Blob(['%PDF-1.4\nDocumento de prueba\n%%EOF'], { type: 'application/pdf' });
        const fields = { title: 'Contrato de integración', client: 'Cliente de prueba', email: 'cliente@example.com', service: 'Migración Cloud', amount: '1200.50', currency: 'PEN', start_date: '2026-11-21', end_date: '2027-11-21', description: 'Alcance', clause_text: 'Pago a 60 días', document: new File([pdf], 'contrato.pdf', { type: 'application/pdf' }) };
        const draftResponse = await post('save_draft', { title: 'Borrador parcial', document: fields.document }, 'index.php?page=new');
        const draftId = new URL(draftResponse.headers.get('location'), base).searchParams.get('draft_id');
        assert.ok(draftId); await follow(draftResponse); assert.match(html, /Borrador guardado/);
        await request('index.php?page=drafts'); assert.match(html, /Borrador parcial/);
        await follow(await post('switch_user', { user_id: 'legal' }));
        await follow(await request(`index.php?page=new&draft_id=${draftId}`)); assert.match(html, /no registra solicitudes/);
        await follow(await post('save_draft', { title: 'No autorizado' }, 'index.php?page=new')); assert.match(html, /no registra solicitudes|Inicia sesión|Solo Comercial/);
        await follow(await post('switch_user', { user_id: 'commercial' }));
        await follow(await post('create_contract', { ...fields, amount: '-1' }, 'index.php?page=new')); assert.match(html, /monto y la moneda/);
        await follow(await post('create_contract', { ...fields, document: new File(['texto'], 'falso.pdf', { type: 'application/pdf' }) }, 'index.php?page=new')); assert.match(html, /PDF válido/);
        const { document: savedFile, ...withoutDocument } = fields;
        const response = await post('create_contract', { ...withoutDocument, draft_id: draftId, review_days: '2' }, `index.php?page=new&draft_id=${draftId}`);
        const location = response.headers.get('location'); const id = new URL(location, base).searchParams.get('id');
        await follow(response); assert.match(html, /Contrato de integración/);
        assert.match(html, /Responsable de la tarea/); assert.match(html, /Diego Mendoza/);
        await request('index.php?page=drafts'); assert.doesNotMatch(html, /Borrador parcial/);
        await request(`index.php?page=detail&id=${id}`);
        const versionId = html.match(/download=([a-f0-9]{32})/)[1];
        let download = await request(`index.php?download=${versionId}`); assert.equal(download.headers.get('content-type'), 'application/pdf'); assert.match(html, /Documento de prueba/);
        await follow(await post('switch_user', { user_id: 'legal' }));
        await follow(await post('observe', { contract_id: id, category: 'Legal', comment: 'Cambiar plazo' }, `index.php?page=detail&id=${id}`)); assert.match(html, /Con observaciones/);
        await follow(await post('switch_user', { user_id: 'commercial' }));
        await follow(await post('add_version', { contract_id: id, summary: 'Plazo corregido', clause_text: 'Pago a 30 días', 'areas[]': ['0'], document: new File([pdf], 'version2.pdf', { type: 'application/pdf' }) }, `index.php?page=detail&id=${id}`)); assert.match(html, /Atendida en V2/); assert.match(html, /Pago a 30 días/);
        for (const role of ['legal','commercial','finance','management']) {
            await follow(await post('switch_user', { user_id: role }));
            await follow(await post('approve', { contract_id: id }, `index.php?page=detail&id=${id}`));
        }
        assert.match(html, /Aprobado/);
        await follow(await post('switch_user', { user_id: 'commercial' }));
        await follow(await post('send', { contract_id: id, email: 'cliente@example.com' }, `index.php?page=detail&id=${id}`)); assert.match(html, /Pendiente de firma/);
        await follow(await post('sign', { contract_id: id, method: 'Firma manual' }, `index.php?page=detail&id=${id}`)); assert.match(html, /Firmado/);
        await follow(await post('switch_user', { user_id: 'admin' })); await request('index.php?page=users'); assert.match(html, /Usuarios y roles/); assert.match(html, /name="action" value="create_user"/);
        await follow(await post('create_user', { name: 'Usuario Prueba', email: 'prueba@example.com', role: 'legal' }, 'index.php?page=users')); assert.match(html, /<strong>Usuario Prueba<\/strong>/);
        const newUserId = html.match(/name="user_id" value="([a-f0-9]{32})"/)[1];
        await follow(await post('update_user', { user_id: newUserId, name: 'Persona editada', email: 'prueba@example.com', role: 'legal', new_password: 'nueva1234' }, 'index.php?page=users')); assert.match(html, /Persona editada/);
        await follow(await post('toggle_user', { user_id: newUserId }, 'index.php?page=users')); assert.match(html, /Inactivo/);
        await follow(await post('toggle_user', { user_id: newUserId }, 'index.php?page=users'));
        await follow(await post('assign_task', { contract_id: 'CL-2026-0010', assignee_id: 'commercial', due_date: '2020-01-01' }, 'index.php?page=detail&id=CL-2026-0010')); assert.match(html, /Seguimiento actualizado/);
        await request('index.php?page=reviews&urgency=overdue'); assert.match(html, /Revisión|Validar|Revisar/);
        await follow(await post('create_backup', {}, 'index.php?page=backups')); assert.match(html, /Respaldo creado/);
        const backupName = html.match(/backup_download=(caleidos_[a-z0-9_.]+\.zip)/)[1];
        const zipResponse = await fetch(new URL(`index.php?backup_download=${backupName}`, base), { headers: { cookie } }); assert.equal(zipResponse.status, 200); const zipBytes = await zipResponse.arrayBuffer();
        await follow(await post('save_draft', { title: 'Posterior al respaldo' }, 'index.php?page=new'));
        await follow(await post('restore_backup', { backup: new File([zipBytes], 'respaldo.zip'), confirm_restore: 'RESTAURAR' }, 'index.php?page=backups')); assert.match(html, /Datos recuperados/);
        await request('index.php?page=drafts'); assert.doesNotMatch(html, /Posterior al respaldo/);
        await request(`index.php?download=${versionId}`); assert.match(html, /Documento de prueba/);
        await follow(await post('restore_backup', { backup: new File(['invalido'], 'respaldo.zip'), confirm_restore: 'RESTAURAR' }, 'index.php?page=backups')); assert.match(html, /no es un respaldo ZIP válido/);
        await request('index.php?export=contracts'); assert.match(html, /Contrato de integración/);
        await request('index.php?export=timeline'); assert.match(html, /Firma registrada/);
        await request('index.php?download=seed-0-1'); assert.match(html, /^%PDF-1.4/);
        await follow(await post('logout')); assert.match(html, /Buenos acuerdos/);
        await follow(await post('login', { email: 'prueba@example.com', password: 'nueva1234' }, 'index.php?page=login')); assert.match(html, /Persona editada/);
        await follow(await post('create_user', { name: 'Intruso', email: 'intruso@example.com', role: 'admin' }, 'index.php?page=users')); assert.match(html, /Administración/);
        await follow(await post('logout'));
        await follow(await post('login', { email: 'admin@caleidos.pe', password: 'demo1234', next: 'users' }, 'index.php?page=login&manage_users=1')); assert.match(html, /name="action" value="create_user"/);
        await follow(await post('logout')); assert.match(html, /Buenos acuerdos/);
        await request(`index.php?download=${versionId}`); assert.match(html, /Buenos acuerdos/);
        for (const resource of ['assets/css/styles.css', 'assets/js/app.js', 'assets/js/modules/forms.js', 'assets/fonts/Goldplay-Regular.woff2', 'assets/caleidos-logo.svg']) { const r = await fetch(new URL(resource, base)); assert.equal(r.status, 200); await r.arrayBuffer(); }
        // Asegura que los directorios privados no son servidos por el router.
        await request('../storage/caleidos.sqlite'); assert.doesNotMatch(html, /SQLite format/);
    } finally {
        server.kill(); await new Promise(resolve => { if (server.exitCode !== null) resolve(); else server.once('exit', resolve); });
        const tempBase = path.resolve(os.tmpdir());
        if (path.dirname(path.resolve(temp)) !== tempBase || !path.basename(temp).startsWith('caleidos-test-')) throw new Error('Ruta temporal fuera del directorio esperado.');
        fs.rmSync(temp, { recursive: true, force: true });
    }
});
