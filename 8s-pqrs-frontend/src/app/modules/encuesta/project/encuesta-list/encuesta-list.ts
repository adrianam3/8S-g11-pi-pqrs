import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { RouterModule } from '@angular/router';
import { ConfirmationService } from 'primeng/api';
import { ButtonModule } from 'primeng/button';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { IconFieldModule } from 'primeng/iconfield';
import { InputIconModule } from 'primeng/inputicon';
import { InputTextModule } from 'primeng/inputtext';
import { Table, TableModule } from 'primeng/table';
import { ToastModule } from 'primeng/toast';
import { IEncuesta } from '../../model/encuesta.interface';
import { ApiService } from '@/modules/Services/api-service';
import { lastValueFrom } from 'rxjs';

@Component({
    selector: 'app-encuesta-list',
    imports: [
        CommonModule,
        RouterModule,
        TableModule,
        ButtonModule,
        ToastModule,
        ConfirmDialogModule,
        InputTextModule,
        IconFieldModule,
        InputIconModule
    ],
    providers: [ConfirmationService],
    templateUrl: './encuesta-list.html',
    styleUrl: './encuesta-list.scss'
})
export class EncuestaList implements OnInit {

    public encuestasAll: any[] = [];
    public loading: boolean = false;
    private encuestaApi = `controllers/encuesta.controller.php?op=`;

    constructor(
        private confirmationService: ConfirmationService,
        private apiService: ApiService,
    ) { }


    ngOnInit(): void {
        this.loadEncuestas();
    }

    onGlobalFilter(table: Table, event: Event) {
        table.filterGlobal((event.target as HTMLInputElement).value, 'contains');
    }

    showFilter() {
    }

    confirmEliminar(data: IEncuesta) {
        this.confirmationService.confirm({
            message: '¿Está seguro de eliminar esta encuesta?',
            header: 'Confirmación',
            icon: 'pi pi-exclamation-triangle',
            accept: () => {
                this.apiService.showToast('success', 'Eliminado', 'Encuesta eliminada');
                // Aquí podrías llamar al API de eliminación si lo tienes implementado
            },
            reject: () => {
                this.apiService.showToast('error', 'Cancelado', 'Encuesta no eliminada');
            }
        });
    }

    async loadEncuestas() {
        this.loading = true;

        try {
            const encuestasObs = await this.apiService.get<IEncuesta[]>(this.encuestaApi + 'todos');
            const data = await lastValueFrom(encuestasObs);
            console.log('data', data);

            this.encuestasAll = data.map(e => ({
                ...e,
                activaTexto: e.activa === 1 ? 'Activa' : 'Inactiva'
            }));

        } catch (error) {
            console.error('Error al cargar encuestas', error);
            this.apiService.showToast('error', 'Error al obtener las encuestas.', 'Error');
        } finally {
            this.loading = false;
        }
    }
}
