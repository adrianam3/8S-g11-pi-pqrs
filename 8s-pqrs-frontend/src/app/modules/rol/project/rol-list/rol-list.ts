import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
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
import { TableModule } from 'primeng/table';
import { ToastModule } from 'primeng/toast';
import { IRol } from '../../model/IRol';
import { ApiService } from '@/modules/Services/api-service';
import { lastValueFrom } from 'rxjs';

@Component({
    selector: 'app-rol-list',
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
    templateUrl: './rol-list.html',
    styleUrl: './rol-list.scss'
})
export class RolList implements OnInit {
    rolesAll: IRol[] = [];
    loading: boolean = false;

    constructor(
        private apiService: ApiService,
        private confirmationService: ConfirmationService,
        private messageService: MessageService
    ) { }

    ngOnInit(): void {
        this.cargarRoles();
    }

    async cargarRoles(): Promise<void> {
        this.loading = true;

        try {
            const rolesObs = await this.apiService.get<any[]>('controllers/roles.controller.php?op=todos');
            const data = await lastValueFrom(rolesObs);
            

            this.rolesAll = data.map(r => ({
                idRol: r.idRol,
                nombre: r.nombreRol,
                descripcion: r.descripcion,
                estado: r.estado === '1' ? 'Activo' : 'Inactivo',
                fechaCreacion: r.fechaCreacion,
                fechaActualizacion: r.fechaActualizacion
            }));

            console.log(this.rolesAll);

        } catch (error) {
            console.error('Error al cargar roles', error);
            this.apiService.showToast('error', 'Error al cargar roles.', 'Error');
        } finally {
            this.loading = false;
        }
    }
    onGlobalFilter(table: any, event: Event) {
        const input = event.target as HTMLInputElement;
        table.filterGlobal(input.value, 'contains');
    }

    showFilter() {
        const table: any = document.querySelector('p-table');
        if (table) {
            table.clear();
        }
    }

    //   confirmEliminar(rol: IRol) {
    //     this.confirmationService.confirm({
    //       message: `¿Está seguro de eliminar el rol "${rol.nombre}"?`,
    //       header: 'Confirmar Eliminación',
    //       icon: 'pi pi-exclamation-triangle',
    //       accept: () => {
    //         this.eliminarRol(rol.idRol);
    //       },
    //     });
    //   }

    //   eliminarRol(idRol: number) {
    //     this.rolService.delete(idRol).subscribe({
    //       next: () => {
    //         this.messageService.add({
    //           severity: 'success',
    //           summary: 'Eliminado',
    //           detail: 'Rol eliminado correctamente',
    //         });
    //         this.cargarRoles();
    //       },
    //       error: () => {
    //         this.messageService.add({
    //           severity: 'error',
    //           summary: 'Error',
    //           detail: 'No se pudo eliminar el rol',
    //         });
    //       },
    //     });
    //   }
}
