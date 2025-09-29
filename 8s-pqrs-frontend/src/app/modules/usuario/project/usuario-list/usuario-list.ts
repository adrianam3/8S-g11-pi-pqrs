import { ApiService } from '@/modules/Services/api-service';
import { CommonModule } from '@angular/common';
import { Component } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { ConfirmationService, MessageService } from 'primeng/api';
import { ButtonModule } from 'primeng/button';
import { ConfirmDialog } from 'primeng/confirmdialog';
import { DialogModule } from 'primeng/dialog';
import { IconFieldModule } from 'primeng/iconfield';
import { InputIconModule } from 'primeng/inputicon';
import { InputTextModule } from 'primeng/inputtext';
import { PasswordModule } from 'primeng/password';
import { ProgressSpinnerModule } from 'primeng/progressspinner';
import { Table, TableModule } from 'primeng/table';
import { ToastModule } from 'primeng/toast';
import { lastValueFrom } from 'rxjs';
import { SelectModule } from 'primeng/select';

type Opcion = { label: string; value: number };

@Component({
    selector: 'app-usuario-list',
    standalone: true,
    imports: [
        CommonModule, FormsModule, RouterModule,
        PasswordModule, ButtonModule, ToastModule,
        DialogModule, ProgressSpinnerModule, TableModule,
        ConfirmDialog, IconFieldModule, InputIconModule,
        InputTextModule, SelectModule
    ],
    providers: [MessageService, ConfirmationService],
    templateUrl: './usuario-list.html',
    styleUrl: './usuario-list.scss'
})
export class UsuarioList {
    public showSearch = false;
    public usuariosAll: any[] = [];
    public loading = false;

    // diálogo
    showDialog = false;
    dialogTitle = 'Nuevo Usuario';
    isEdit = false;

    // combos
    personasOpts: Opcion[] = [];
    rolesOpts: Opcion[] = [];
    agenciasOpts: Opcion[] = [];

    // formulario
    form: any = {
        idUsuario: 0,
        usuario: '',
        password: '',
        descripcion: '',
        idPersona: null,
        idAgencia: null,
        idRol: null,
        estado: 1
    };

    private apiList = `controllers/usuarios.controller.php?op=todos`;
    private apiCtrl = `controllers/usuarios.controller.php`;
    private apiPerson = `controllers/personas.controller.php?op=todossinusuario`;
    private apiRoles = `controllers/roles.controller.php?op=todos`;      // existe en tu backend
    private apiAgenc = `controllers/agencia.controller.php?op=todos`;   // existe en tu backend

    constructor(
        private api: ApiService,
        private confirm: ConfirmationService,
        private toast: MessageService
    ) { }

    ngOnInit() {
        this.loadUsuarios();
    }

    onGlobalFilter(table: Table, ev: Event) {
        table.filterGlobal((ev.target as HTMLInputElement).value, 'contains');
    }
    showFilter() { this.showSearch = !this.showSearch; }

    // ========== LISTADO ==========
    async loadUsuarios() {
        this.loading = true;
        try {
            const obs = this.api.get<any[]>(this.apiList);
            const data: any = await lastValueFrom(obs);
            const rows = Array.isArray(data) ? data : (data?.data ?? []);
            this.usuariosAll = rows.map((u: any) => ({
                idUsuario: u.idUsuario,
                usuario: u.usuario,
                nombreCompleto: `${u.personaNombres ?? ''} ${u.personaApellidos ?? ''}`.trim(),
                email: u.personaEmail,
                descRol: u.rolNombre,
                agenciaNombre: u.agenciaNombre,
                fechaCreacion: u.fechaCreacion,
                idRol: u.idRol,
                idPersona: u.idPersona,
                idAgencia: u.idAgencia,
                descEstado: +u.estado === 1 ? 'Activo' : 'Inactivo'
            }));
        } catch (e) {
            console.error(e);
            this.api.showToast('error', 'No fue posible cargar usuarios.', ' Error');
        } finally {
            this.loading = false;
        }
    }

    // ========== DIALOGO ==========
    async openNew() {
        this.isEdit = false;
        this.dialogTitle = 'Nuevo Usuario';
        this.form = {
            idUsuario: 0,
            usuario: '',
            password: '',
            descripcion: '',
            idPersona: null,
            idAgencia: null,
            idRol: null,
            estado: 1
        };
        await this.loadCombos(true); // personas SIN usuario para alta
        this.showDialog = true;
    }

    async openEdit(row: any) {
        this.isEdit = true;
        this.dialogTitle = 'Editar Usuario';
        // cargar datos completos (opcional)
        try {
            const res = await lastValueFrom(
                this.api.get(`${this.apiCtrl}?op=uno&idUsuario=${row.idUsuario}`)
            );
            const u = res ?? row;
            this.form = {
                idUsuario: +u.idUsuario,
                usuario: u.usuario ?? '',
                password: '', // no se edita aquí
                descripcion: u.descripcion ?? '',
                idPersona: +u.idPersona,
                idAgencia: +u.idAgencia,
                idRol: +u.idRol,
                estado: u.estado ?? 1
            };
            await this.loadCombos(false, this.form.idPersona); // incluir la persona actual
            this.showDialog = true;
        } catch (e) {
            console.error(e);
            this.api.showToast('error', 'No fue posible cargar el usuario.', '');
        }
    }

    closeDialog() { this.showDialog = false; }

    // ========== COMBOS ==========
    private async loadCombos(onlyFreePersons = true, currentIdPersona?: number) {
        console.log('ingreso')
        // Personas
        try {
            const src = this.api.get<any[]>(this.apiPerson)
            const pData: any = await lastValueFrom(src);
            console.log(pData)
            const rows = Array.isArray(pData) ? pData : (pData?.data ?? []);
            const personas = rows.map((p: any) => ({
                label: `${p.nombres} ${p.apellidos}`.trim(),
                value: +p.idPersona
            }));

            // si edito, aseguro que la persona actual esté en la lista
            if (!onlyFreePersons && currentIdPersona && !personas.some((x: any) => x.value === currentIdPersona)) {
                personas.push({ label: '(Persona actual)', value: currentIdPersona });
            }
            this.personasOpts = personas.sort(
                (a: Opcion, b: Opcion) => a.label.localeCompare(b.label)
            );

            // this.personasOpts = personas.sort((a, b) => a.label.localeCompare(b.label));
        } catch { this.personasOpts = []; }

        // Roles
        try {
            const rData: any = await lastValueFrom(this.api.get<any[]>(this.apiRoles));
            const rRows = Array.isArray(rData) ? rData : (rData?.data ?? []);
            this.rolesOpts = rRows.map((r: any) => ({ label: r.nombreRol ?? r.nombre ?? `Rol ${r.idRol}`, value: +r.idRol }));
        } catch { this.rolesOpts = []; }

        // Agencias
        try {
            const aData: any = await lastValueFrom(this.api.get<any[]>(this.apiAgenc));
            const aRows = Array.isArray(aData) ? aData : (aData?.data ?? []);
            this.agenciasOpts = aRows.map((a: any) => ({ label: a.nombre ?? `Agencia ${a.idAgencia}`, value: +a.idAgencia }));
        } catch { this.agenciasOpts = []; }
    }

    // ========== GUARDAR ==========
    save() {
        // validaciones mínimas
        if (!this.form.usuario || !this.form.idPersona || !this.form.idRol || !this.form.idAgencia) {
            this.api.showToast('warn', 'Complete usuario, persona, rol y agencia.', '');
            return;
        }
        if (!this.isEdit && !this.form.password) {
            this.api.showToast('warn', 'La contraseña es obligatoria para un nuevo usuario.', '');
            return;
        }

        const payload: any = {
            idUsuario: this.form.idUsuario,
            usuario: (this.form.usuario as string).trim(),
            password: this.form.password || undefined,
            descripcion: this.form.descripcion ?? '',
            idPersona: +this.form.idPersona,
            idAgencia: +this.form.idAgencia,
            idRol: +this.form.idRol,
            estado: +this.form.estado
        };

        const op = this.isEdit ? 'actualizar' : 'insertar';
        this.api.post(`${this.apiCtrl}?op=${op}`, payload).subscribe({
            next: (r: any) => {
                this.api.showToast('success', this.isEdit ? 'Usuario actualizado' : 'Usuario creado', '');
                this.showDialog = false;
                this.loadUsuarios();
            },
            error: (e) => {
                console.error(e);
                this.api.showToast('error', e?.error?.message ?? 'No fue posible guardar el usuario.', '');
            }
        });
    }

    // ========== ELIMINAR ==========
    askDelete(row: any) {
        this.confirm.confirm({
            header: 'Eliminar usuario',
            message: `¿Eliminar al usuario <b>${row.usuario}</b>?`,
            icon: 'pi pi-exclamation-triangle',
            acceptLabel: 'Sí, eliminar',
            rejectLabel: 'Cancelar',
            accept: () => this.hardDelete(row)
        });
    }

    private hardDelete(row: any) {
        this.api.post(`${this.apiCtrl}?op=eliminar`, { idUsuario: row.idUsuario })
            .subscribe({
                next: (r: any) => {
                    if (r?.status === 'error') {
                        this.api.showToast('warn', r?.message ?? 'No se puede eliminar.', '');
                        return;
                    }
                    this.api.showToast('success', 'Usuario eliminado', '');
                    this.loadUsuarios();
                },
                error: (e) => {
                    console.error(e);
                    if (e?.status === 409) {
                        this.api.showToast('warn', e?.error?.message ?? 'No se puede eliminar (vinculado).', '');
                    } else {
                        this.api.showToast('error', 'Error eliminando el usuario.', '');
                    }
                }
            });
    }
}
