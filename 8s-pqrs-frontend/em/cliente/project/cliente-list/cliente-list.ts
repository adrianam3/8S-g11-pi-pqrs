import { AtencionList } from '@/modules/atencion/project/atencion-list/atencion-list';
import { CommonModule } from '@angular/common';
import { Component } from '@angular/core';
import { RouterModule } from '@angular/router';
import { TabsModule } from 'primeng/tabs';
import { EncuProgramadaList } from "@/modules/encuesta-programada/project/encu-programada-list/encu-programada-list";

@Component({
    selector: 'app-cliente-list',
    imports: [
    TabsModule,
    RouterModule,
    CommonModule,
    AtencionList,
    EncuProgramadaList,
],
    templateUrl: './cliente-list.html',
    styleUrl: './cliente-list.scss'
})
export class ClienteList {
    selectedTabIndex = 0;
    tabs = [
        { title: 'Atenciones', value: 0, icon: 'pi pi-list' },
        { title: 'Encuestas programadas', value: 1, icon: 'pi pi-list' },
        { title: 'Tab 3', value: 2, icon: 'pi pi-list' },
    ];

}
