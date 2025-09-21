import { ApiService } from '@/modules/Services/api-service';
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
import { lastValueFrom } from 'rxjs';

@Component({
    selector: 'app-tipo-pqrs-list',
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
    providers: [ConfirmationService, MessageService],
    templateUrl: './tipo-pqrs-list.html',
    styleUrl: './tipo-pqrs-list.scss'
})
export class TipoPqrsList implements OnInit {
    tipospqrsAll: any[] = [];
    loading: boolean = false;
    readonly tiposPqrsApi = 'controllers/tipospqrs.controller.php?op=todos';

    constructor(
        private apiService: ApiService,
        private messageService: MessageService
    ) { }

    async ngOnInit() {
        await this.loadTiposPqrs();
    }

    async loadTiposPqrs(): Promise<void> {
        this.loading = true;
        try {
            const obs = await this.apiService.get<any[]>(this.tiposPqrsApi);
            const data = await lastValueFrom(obs);

            this.tipospqrsAll = data?.map((t) => ({
                idTipo: t.idTipo,
                nombre: t.nombre,
                descripcion: t.descripcion,
                estado: t.estado === '1' ? 'Activo' : 'Inactivo',
                fechaCreacion: t.fechaCreacion,
                fechaActualizacion: t.fechaActualizacion,
            }));
        } catch (error) {
            console.error('Error al cargar tipos PQRS:', error);
            this.apiService.showToast(
                'error',
                'Error al obtener los Tipos de PQRS',
                'Error'
            );
        } finally {
            this.loading = false;
        }
    }

    confirmEliminar(row: any) {
        // Aquí puedes implementar lógica para eliminar
        console.log('Eliminar', row);
    }

    onGlobalFilter(dt: any, event: Event): void {
        const inputElement = event.target as HTMLInputElement;
        const value = inputElement?.value || '';
        dt.filterGlobal(value, 'contains');
    }
}
