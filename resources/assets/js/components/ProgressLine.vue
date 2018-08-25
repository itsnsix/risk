<template>
    <div class="progress-line">
        <div class="progress-fill" :style="{
            'width': parseFloat(value / max * 100).toFixed(2) + '%',
            'background-color': color
        }"></div>
    </div>
</template>

<script>
    export default {
        props: [
            'value',
            'max',
            'color'
        ]
    }
</script>