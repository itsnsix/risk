<template>
    <div class="main-outer">
        <div class="main-navbar">
            <span>BATORU</span>

            <div class="help-toggle" @click="toggleHelpModal">?</div>
            <div @click="toggleLabels" class="label-toggle">
                <img :class="{active: showLabels}" class="user-label" src="/images/default_avatar.png"/></div>
            <div class="navbar-toggle" @click="toggleEventMenu">
                <div></div>
                <div></div>
                <div></div>
            </div>
        </div>

        <div class="main">
            <div class="map-container dragscroll">
                <popover :data="popover"></popover>

                <transition name="loading">
                    <div class="loading" v-if="status">{{status}}</div>
                </transition>

                <canvas @click="onMapClick" id="map" width="5644" height="2177"></canvas>

                <div class="user-labels" v-show="showLabels && !status">
                    <img class="user-label" :src="label.avatar" v-for="label in labels"
                         :id="'territory-' + label.territory.id"
                         :style="{top: label.y + 'px', left: label.x + 'px',
                            height: label.size + 'px', width: label.size + 'px'}">
                </div>
            </div>

            <sidebar></sidebar>

            <help-modal></help-modal>
        </div>
    </div>
</template>

<script>
    import Helpers from '../helpers';
    import HelpModal from './HelpModal';
    import Sidebar from './Sidebar';
    import Popover from './Popover';

    export default {
        components:{
            HelpModal,
            Sidebar,
            Popover,
        },

	    data() {
	    	return {
			    status: 'Loading map...',
			    territories: {},
                canvas: null,
                context: null,
                labels: [],
			    popover: null,
                showLabels: true,
	    	}
	    },

	    mounted() {
            let labelWorker = new Worker('js/labler.js');
            labelWorker.addEventListener('message', e => {
                this.labels = e.data;
            }, false);
            let painterWorker = new Worker('js/painter.js');
            painterWorker.addEventListener('message', e => {
                this.context.putImageData(e.data, 0, 0);
                this.status = null;
            }, false);

	    	axios.get('/territories')
                .then(response => {
                    this.status = 'Drawing map...';
                    this.territories = response.data;

                    labelWorker.postMessage(this.territories);
                    this.setupMap(painterWorker);
                })
                .catch(response => {
                    this.status = 'Failed to load map.';
                });

	    	Bus.$on('scroll-to-territory', this.scrollToTerritory);
	    	Bus.$on('close-popover', this.closePopover);
        },

	    methods: {
            toggleHelpModal: function() {
                Bus.$emit('toggle-help-modal');
            },

            toggleLabels: function() {
                this.showLabels = !this.showLabels;
            },

	        scrollToTerritory: function(territoryID) {
                let turnOffLabels = !this.showLabels;
                this.showLabels = true; // Needs to be visible in order to scroll to them.
                setTimeout(() => {
                    let el = document.getElementById('territory-' + territoryID);
                    if (el) {
                        el.scrollIntoView({behavior: 'smooth', block: 'center', inline: 'center'});

                        this.labels.forEach(l => {
                            if (l.territory.id === territoryID) {
                                this.popover = {
                                    territory: l.territory,
                                    x: l.x - 125,
                                    y: l.y + 15
                                };
                            }
                        });
                    } else {
                        console.log('No label for this territory.')
                    }

                    if (turnOffLabels) this.showLabels = false;
                });
            },

		    closePopover: function() {
		    	this.popover = null;
            },

		    toggleEventMenu: function() {
                Bus.$emit('toggle-sidebar');
            },

		    onMapClick: function(event) {
                // Show territory occupation if it exists.
			    let t = this.findTerritory({x: event.layerX, y: event.layerY});
			    if (t) {
				    this.popover = {
				    	territory: t,
                        x: event.layerX - 125, // Remove half the width of popover to center it.
                        y: event.layerY + 15
                    };
                } else {
			    	this.popover = null;
                }
            },

            findTerritory: function(location) {
                if (!this.context) {return null;}
	            let imageData = this.context.getImageData(0, 0, this.canvas.width, this.canvas.height);
	            if (!imageData) {return null;}

	            let color = Helpers.hexToRgb('#18424C'); // Reserved ocean color
	            let coloredPixels = 0;

	            let pixelStack = [[location.x, location.y]];

	            if (!Helpers.validateInitialPixel(imageData, this.canvas.width,
			            pixelStack[0][0], pixelStack[0][1])) {
		            return null;
	            }

	            while (pixelStack.length) {
		            // Where we current are in the image
		            let currentPosition = pixelStack.pop();
		            let x = currentPosition[0];
		            let y = currentPosition[1];
		            let pixelPosition = (y * this.canvas.width + x) * 4;
		            let matchingColor = {
			            r: imageData.data[pixelPosition],
			            g: imageData.data[pixelPosition + 1],
			            b: imageData.data[pixelPosition + 2]
		            };

		            // Walk upwards from position until we hit another color than the one we clicked on
		            while (y-- >= 0 && Helpers.matchPixelColor(imageData, pixelPosition, matchingColor, color)) {
			            pixelPosition -= this.canvas.width * 4;
		            }

		            pixelPosition += this.canvas.width * 4;
		            ++y;
		            let reachLeft = false, reachRight = false;
		            // Walk downwards and look for pixels of the same color to the left and right
		            while (y++ < this.canvas.height - 1 && Helpers.matchPixelColor(imageData, pixelPosition, matchingColor, color)) {
			            Helpers.colorPixel(imageData, pixelPosition, color);
			            coloredPixels++;

			            // Check if these coordinates is a territory
			            if (this.territories.hasOwnProperty(x + '-' + y)) {
			            	return this.territories[x + '-' + y];
                        }

			            // Check left
			            if (x > 0) {
				            if (Helpers.matchPixelColor(imageData, pixelPosition - 4, matchingColor, color)) {
					            if (!reachLeft) {
						            pixelStack.push([x - 1, y]);
						            reachLeft = true;
					            }
				            } else if (reachLeft) {
					            reachLeft = false;
				            }
			            }

			            // Check right
			            if (x < this.canvas.width - 1) {
				            if (Helpers.matchPixelColor(imageData, pixelPosition + 4, matchingColor, color)) {
					            if (!reachRight) {
						            pixelStack.push([x + 1, y]);
						            reachRight = true;
					            }
				            } else if (reachRight) {
					            reachRight = false;
				            }
			            }

			            pixelPosition += this.canvas.width * 4;
		            }

		            if (coloredPixels > 50000) {
			            console.log('Too big area');
			            return null;
		            }
	            }

	            return null;
            },

		    setupMap: function(painter) {
			    this.canvas = document.getElementById('map');
			    this.context = this.canvas.getContext('2d');

			    let image = new Image();
			    image.src = '/images/map.png';
			    image.onload = () => {
				    this.context.drawImage(image, 0, 0);
                    let imageData = this.context.getImageData(0, 0, this.canvas.width, this.canvas.height);
				    painter.postMessage({
                        imageData: imageData,
                        territories: this.territories,
                        dimensions: {width: this.canvas.width, height: this.canvas.height}
				    });
			    };
		    }
	    }
    }
</script>
