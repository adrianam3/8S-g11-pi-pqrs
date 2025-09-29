import { ApiService } from '@/modules/Services/api-service';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
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

@Component({
    selector: 'app-usuario-list',
    imports: [
        CommonModule,
        FormsModule,
        RouterModule,
        PasswordModule,
        ButtonModule,
        ToastModule,
        DialogModule,
        ProgressSpinnerModule,
        TableModule,
        ConfirmDialog,
        IconFieldModule,
        InputIconModule,
        InputTextModule,
    ],
    standalone: true,
    providers: [MessageService, ConfirmationService],
    templateUrl: './usuario-list.html',
    styleUrl: './usuario-list.scss'
})
export class UsuarioList {

    public usuarios: any;
    public showSearch: boolean = false;
    public usuariosAll: any = [];
    private usuarioApi = `controllers/usuarios.controller.php?op=todos`;
    public loading: boolean = false;

    constructor(
        private http: HttpClient,
        private confirmationService: ConfirmationService,
        private messageService: MessageService,
        private apiService: ApiService,
    ) { }

    ngOnInit(): void {
        this.loadUsuarios(); // Llamar al método que carga los datos
    }

    onGlobalFilter(table: Table, event: Event) {
        table.filterGlobal((event.target as HTMLInputElement).value, 'contains');
    }

    showFilter() {
        this.showSearch = !this.showSearch;
    }

    confirmEliminar(data: any) {
        this.confirmationService.confirm({
            message: '¿Está seguro de eliminar este usuario?',
            header: 'Confirmación',
            icon: 'pi pi-exclamation-triangle',
            accept: () => {
                this.messageService.add({ severity: 'info', summary: 'Confirmado', detail: 'Usuario eliminado' });
            },
            reject: () => {
                this.messageService.add({ severity: 'error', summary: 'Rechazado', detail: 'Usuario no eliminado' });
            }
        });
    }

    /** 🔹 Método para obtener la lista de usuarios */
    async loadUsuarios() {

        try {
            const usuariosObs = await this.apiService.get<any[]>(this.usuarioApi);
            const data = await lastValueFrom(usuariosObs);

            this.usuariosAll = data.map(u => ({
                idUsuario: u.idUsuario,
                nombreCompleto: `${u.personaNombres} ${u.personaApellidos}`,
                descRol: u.rolNombre,
                agenciaNombre: u.agenciaNombre,
                email: u.personaEmail,
                fechaCreacion: u.fechaCreacion,
                usuario: u.usuario,
                idRol: u.idRol,
                descEstado: u.estado === 1 ? 'Activo' : 'Inactivo'
            }));

            console.log(this.usuariosAll)

        } catch (error) {
            console.error('Error al cargar usuarios', error);
            this.apiService.showToast('error', 'Error al obtener los usuarios.', 'Errr');
        } finally {

        }
    }
}
