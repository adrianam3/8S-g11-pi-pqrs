import { Component } from '@angular/core';
import { ButtonModule } from 'primeng/button';
import { DynamicDialogRef } from 'primeng/dynamicdialog';
import { ScrollPanelModule } from 'primeng/scrollpanel';

@Component({
    selector: 'app-politica-dialog-component',
    imports: [ScrollPanelModule, ButtonModule],
    templateUrl: './politica-dialog-component.html',
    styleUrls: ['./politica-dialog-component.scss']
})
export class PoliticaDialogComponent {
    constructor(public ref: DynamicDialogRef) { }

    cerrar() {
        this.ref.close();
    }
}

